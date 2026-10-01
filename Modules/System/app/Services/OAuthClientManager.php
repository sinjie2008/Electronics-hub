<?php

declare(strict_types=1);

namespace Modules\System\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

final class OAuthClientManager
{
    public function __construct(private ClientRepository $clients) {}

    public function list(User $actor): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('oauth-clients.view');

        return Passport::client()
            ->newQuery()
            ->select(['id', 'name', 'redirect_uris', 'grant_types', 'revoked', 'created_at', 'updated_at'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);
    }

    public function createPublicPkceClient(User $actor, string $name, string $redirectUri): Client
    {
        Gate::forUser($actor)->authorize('oauth-clients.create');

        $this->validateName($name);
        $this->validateRedirectUri($redirectUri);

        return DB::transaction(function () use ($actor, $name, $redirectUri): Client {
            $client = $this->clients->createAuthorizationCodeGrantClient(
                name: $name,
                redirectUris: [$redirectUri],
                confidential: false,
            );

            $this->logCreated($actor, $client, 'authorization_code_pkce');

            return $client;
        });
    }

    public function createClientCredentialsClient(User $actor, string $name): Client
    {
        Gate::forUser($actor)->authorize('oauth-clients.create');

        $this->validateName($name);

        return DB::transaction(function () use ($actor, $name): Client {
            $client = $this->clients->createClientCredentialsGrantClient($name);

            $this->logCreated($actor, $client, 'client_credentials');

            return $client;
        });
    }

    public function revoke(User $actor, string $clientId): void
    {
        Gate::forUser($actor)->authorize('oauth-clients.revoke');

        DB::transaction(function () use ($actor, $clientId): void {
            $client = Passport::client()->newQuery()->lockForUpdate()->find($clientId);

            if (! $client instanceof Client) {
                throw (new ModelNotFoundException)->setModel(Client::class, [$clientId]);
            }

            if ($client->revoked) {
                return;
            }

            // Passport's repository revokes the client, access tokens, and refresh tokens together.
            $this->clients->delete($client);

            activity('administration')
                ->causedBy($actor)
                ->performedOn($client)
                ->withProperties([
                    'client_name' => $client->name,
                    'grant_type' => implode(' ', $client->grant_types ?? []),
                ])
                ->event('oauth-client.revoked')
                ->log('OAuth client revoked');
        });
    }

    private function validateName(string $name): void
    {
        Validator::make(['name' => $name], [
            'name' => ['required', 'string', 'min:2', 'max:100'],
        ])->validate();
    }

    private function validateRedirectUri(string $redirectUri): void
    {
        Validator::make(['redirect_uri' => $redirectUri], [
            'redirect_uri' => ['required', 'string', 'max:2048'],
        ])->validate();

        $parts = parse_url($redirectUri);

        if (
            filter_var($redirectUri, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw ValidationException::withMessages([
                'redirect_uri' => 'The redirect URI must be a valid HTTPS URL, or a loopback HTTP URL in local development.',
            ]);
        }

        if (strtolower($parts['scheme']) === 'https') {
            return;
        }

        if (
            strtolower($parts['scheme']) === 'http'
            && app()->environment('local')
            && $this->isLoopbackHost($parts['host'])
        ) {
            return;
        }

        throw ValidationException::withMessages([
            'redirect_uri' => 'The redirect URI must be HTTPS. HTTP is only allowed for loopback URLs in local development.',
        ]);
    }

    private function isLoopbackHost(string $host): bool
    {
        $host = trim($host, '[]');

        if (strtolower($host) === 'localhost') {
            return true;
        }

        $packedAddress = inet_pton($host);

        if ($packedAddress === false) {
            return false;
        }

        if (strlen($packedAddress) === 4) {
            return ord($packedAddress[0]) === 127;
        }

        return $packedAddress === str_repeat("\0", 15)."\1";
    }

    private function logCreated(User $actor, Client $client, string $grantType): void
    {
        activity('administration')
            ->causedBy($actor)
            ->performedOn($client)
            ->withProperties([
                'client_name' => $client->name,
                'grant_type' => $grantType,
            ])
            ->event('oauth-client.created')
            ->log('OAuth client created');
    }
}
