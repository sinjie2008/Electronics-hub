<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Modules\IAM\Models\Permission;
use Modules\System\Services\OAuthClientManager;
use phpseclib4\Crypt\RSA;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

final class PassportSearchTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->configurePassportKeys();
    }

    public function test_public_health_endpoint_returns_only_a_generic_status(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_authorization_code_pkce_issues_a_user_token_and_me_returns_only_safe_fields(): void
    {
        $user = User::factory()->unverified()->create();
        $issued = $this->issueUserToken($user, ['profile:read']);

        $this->withToken($issued['access_token'])
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->getKey())
            ->assertJsonPath('data.name', $user->name)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.verified', false)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.permissions')
            ->assertJsonMissingPath('data.is_active');
    }

    public function test_me_requires_a_profile_read_scope(): void
    {
        $user = User::factory()->create();
        $issued = $this->issueUserToken($user, []);

        $this->withToken($issued['access_token'])
            ->getJson('/api/v1/me')
            ->assertForbidden();
    }

    public function test_api_rejects_an_inactive_user_even_when_the_token_is_valid(): void
    {
        $user = User::factory()->create();
        $issued = $this->issueUserToken($user, ['profile:read']);
        $user->forceFill(['is_active' => false])->save();

        $this->withToken($issued['access_token'])
            ->getJson('/api/v1/me')
            ->assertForbidden();
    }

    public function test_user_search_uses_database_scout_and_returns_only_active_matching_users(): void
    {
        $actor = $this->userWithPermissions(['search.use', 'users.view']);
        $match = User::factory()->create([
            'name' => 'Searchable Example',
            'email' => 'searchable@example.test',
        ]);
        $inactiveMatch = User::factory()->create([
            'name' => 'Searchable Inactive',
            'email' => 'inactive-searchable@example.test',
            'is_active' => false,
        ]);
        User::factory()->create([
            'name' => 'Unrelated Example',
            'email' => 'unrelated@example.test',
        ]);

        $issued = $this->issueUserToken($actor, ['users:search']);

        $response = $this->withToken($issued['access_token'])
            ->getJson('/api/v1/search/users?query=Searchable');

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $match->getKey(),
                'name' => $match->name,
                'email' => $match->email,
                'verified' => true,
            ])
            ->assertJsonMissing([
                'id' => $inactiveMatch->getKey(),
                'name' => $inactiveMatch->name,
            ])
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.remember_token')
            ->assertJsonMissingPath('data.0.permissions');
    }

    public function test_user_search_requires_a_bearer_token_and_the_users_search_scope(): void
    {
        $this->getJson('/api/v1/search/users?query=Example')->assertUnauthorized();

        $actor = $this->userWithPermissions(['search.use', 'users.view']);
        $issued = $this->issueUserToken($actor, ['profile:read']);

        $this->withToken($issued['access_token'])
            ->getJson('/api/v1/search/users?query=Example')
            ->assertForbidden();
    }

    public function test_user_search_requires_both_search_permission_and_user_policy_access(): void
    {
        $withoutPermission = $this->userWithPermissions(['users.view']);
        $issuedWithoutPermission = $this->issueUserToken($withoutPermission, ['users:search']);

        $this->withToken($issuedWithoutPermission['access_token'])
            ->getJson('/api/v1/search/users?query=Example')
            ->assertForbidden();

        $withoutPolicyAccess = $this->userWithPermissions(['search.use']);
        $issuedWithoutPolicyAccess = $this->issueUserToken($withoutPolicyAccess, ['users:search']);

        $this->withToken($issuedWithoutPolicyAccess['access_token'])
            ->getJson('/api/v1/search/users?query=Example')
            ->assertForbidden();
    }

    public function test_user_search_rejects_short_long_wildcard_and_oversized_page_inputs(): void
    {
        $actor = $this->userWithPermissions(['search.use', 'users.view']);
        $issued = $this->issueUserToken($actor, ['users:search']);

        foreach ([
            '/api/v1/search/users?query=a',
            '/api/v1/search/users?query='.str_repeat('a', 101),
            '/api/v1/search/users?query=%25%25',
            '/api/v1/search/users?query=Example&per_page=51',
        ] as $uri) {
            $this->withToken($issued['access_token'])
                ->getJson($uri)
                ->assertUnprocessable();
        }
    }

    public function test_client_credentials_scope_and_revoke_control_the_machine_status_endpoint(): void
    {
        $client = app(ClientRepository::class)->createClientCredentialsGrantClient('Status integration');
        $secret = $client->plainSecret;

        $token = $this->post(route('passport.token'), [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getKey(),
            'client_secret' => $secret,
            'scope' => 'system:read',
        ])->assertOk()->json('access_token');

        $this->withToken($token)
            ->getJson('/api/v1/integration/status')
            ->assertOk()
            ->assertExactJson(['status' => 'available']);

        $clientWithoutScope = app(ClientRepository::class)->createClientCredentialsGrantClient('Limited integration');
        $limitedToken = $this->post(route('passport.token'), [
            'grant_type' => 'client_credentials',
            'client_id' => $clientWithoutScope->getKey(),
            'client_secret' => $clientWithoutScope->plainSecret,
        ])->assertOk()->json('access_token');

        $this->withToken($limitedToken)
            ->getJson('/api/v1/integration/status')
            ->assertForbidden();

        $actor = $this->userWithPermissions(['oauth-clients.revoke']);
        app(OAuthClientManager::class)->revoke($actor, $client->getKey());

        $this->assertTrue($client->fresh()->revoked);
        $this->assertTrue($client->tokens()->sole()->revoked);

        $this->withToken($token)
            ->getJson('/api/v1/integration/status')
            ->assertUnauthorized();
    }

    public function test_client_manager_creates_public_pkce_and_confidential_machine_clients_without_logging_secrets(): void
    {
        $actor = $this->userWithPermissions(['oauth-clients.create', 'oauth-clients.view']);
        $manager = app(OAuthClientManager::class);

        $publicClient = $manager->createPublicPkceClient(
            $actor,
            'Public mobile app',
            'https://client.example.test/oauth/callback',
        );
        $machineClient = $manager->createClientCredentialsClient($actor, 'Nightly integration');
        $machineSecret = $machineClient->plainSecret;

        $this->assertFalse($publicClient->confidential());
        $this->assertNull($publicClient->plainSecret);
        $this->assertSame(['authorization_code', 'refresh_token'], $publicClient->getAttribute('grant_types'));
        $this->assertSame(['https://client.example.test/oauth/callback'], $publicClient->getAttribute('redirect_uris'));
        $this->assertTrue($machineClient->confidential());
        $this->assertNotEmpty($machineSecret);
        $this->assertTrue(Hash::check($machineSecret, $machineClient->getRawOriginal('secret')));

        $clientList = $manager->list($actor);
        $listedMachine = $clientList->getCollection()->firstWhere('id', $machineClient->getKey());

        $this->assertNotNull($listedMachine);
        $this->assertArrayNotHasKey('secret', $listedMachine->getAttributes());
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Client::class,
            'subject_id' => $machineClient->getKey(),
            'event' => 'oauth-client.created',
            'log_name' => 'administration',
        ]);

        $createdActivity = Activity::query()
            ->where('subject_type', Client::class)
            ->where('subject_id', $machineClient->getKey())
            ->where('event', 'oauth-client.created')
            ->sole();

        $this->assertSame('client_credentials', $createdActivity->properties['grant_type']);
        $this->assertStringNotContainsString($machineSecret, $createdActivity->properties->toJson());
    }

    public function test_client_manager_rejects_insecure_redirects_and_unauthorized_creation(): void
    {
        $actor = $this->userWithPermissions(['oauth-clients.create']);
        $manager = app(OAuthClientManager::class);

        try {
            $manager->createPublicPkceClient($actor, 'Insecure client', 'http://client.example.test/callback');
            $this->fail('An HTTP redirect URI outside a local loopback should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('redirect_uri', $exception->errors());
        }

        $unprivilegedActor = User::factory()->create();

        $this->expectException(AuthorizationException::class);
        $manager->createClientCredentialsClient($unprivilegedActor, 'Unauthorized client');
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
    private function userWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create();

        foreach ($permissionNames as $permissionName) {
            $user->givePermissionTo(Permission::findOrCreate($permissionName, 'web'));
        }

        return $user;
    }

    /**
     * @param  array<int, string>  $scopes
     * @return array{access_token: string, client: Client}
     */
    private function issueUserToken(User $user, array $scopes): array
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            name: 'PKCE test client',
            redirectUris: ['https://client.example.test/oauth/callback'],
            confidential: false,
        );
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = Str::random(32);
        $redirectUri = 'https://client.example.test/oauth/callback';

        $this->actingAs($user, 'web');

        $authorization = $this->get(route('passport.authorizations.authorize', [
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]));

        $authorization->assertOk();

        $authorizationToken = session('authToken');
        $this->assertNotEmpty($authorizationToken);

        $approval = $this->post(route('passport.authorizations.approve'), [
            'auth_token' => $authorizationToken,
        ]);

        $approval->assertRedirect();

        parse_str((string) parse_url($approval->headers->get('Location'), PHP_URL_QUERY), $callbackParameters);
        $authorizationCode = $callbackParameters['code'] ?? null;

        $this->assertNotEmpty($authorizationCode);
        $this->assertSame($state, $callbackParameters['state'] ?? null);

        $token = $this->post(route('passport.token'), [
            'grant_type' => 'authorization_code',
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirectUri,
            'code' => $authorizationCode,
            'code_verifier' => $verifier,
        ])->assertOk();

        return [
            'access_token' => $token->json('access_token'),
            'client' => $client,
        ];
    }

    private function configurePassportKeys(): void
    {
        $directory = storage_path('framework/testing/passport');

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $privateKeyPath = $directory.'/oauth-private.key';
        $publicKeyPath = $directory.'/oauth-public.key';

        if (! file_exists($privateKeyPath) || ! file_exists($publicKeyPath)) {
            $key = RSA::createKey(2048);

            file_put_contents($privateKeyPath, (string) $key);
            file_put_contents($publicKeyPath, (string) $key->getPublicKey());
            chmod($privateKeyPath, 0600);
            chmod($publicKeyPath, 0600);
        }

        chmod($privateKeyPath, 0600);
        chmod($publicKeyPath, 0600);
        Passport::loadKeysFrom($directory);
    }
}
