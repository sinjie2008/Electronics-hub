<?php

declare(strict_types=1);

namespace Modules\System\Filament\Pages;

use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Passport\Client;
use Modules\System\Services\OAuthClientManager;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

final class OAuthClients extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Key;

    protected static ?string $navigationLabel = 'OAuth Clients';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'system/oauth-clients';

    protected static ?string $title = 'OAuth Clients';

    protected string $view = 'system::filament.pages.oauth-clients';

    protected OAuthClientManager $clientManager;

    public function boot(OAuthClientManager $clientManager): void
    {
        $this->clientManager = $clientManager;
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('oauth-clients.view') ?? false;
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Create OAuth client')
                ->modalSubmitActionLabel('Create and download credentials')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->minLength(2)
                        ->maxLength(100),
                    Select::make('grant_type')
                        ->options([
                            'authorization_code_pkce' => 'Public client (Authorization Code + PKCE)',
                            'client_credentials' => 'Machine client (Client Credentials)',
                        ])
                        ->default('authorization_code_pkce')
                        ->required()
                        ->live(),
                    TextInput::make('redirect_uri')
                        ->label('Redirect URI')
                        ->url()
                        ->maxLength(2048)
                        ->visible(fn (Get $get): bool => $get('grant_type') === 'authorization_code_pkce')
                        ->required(fn (Get $get): bool => $get('grant_type') === 'authorization_code_pkce'),
                ])
                ->visible(fn (): bool => Auth::user()?->can('oauth-clients.create') ?? false)
                ->action(function (array $data): StreamedResponse {
                    $actor = Auth::user();
                    abort_unless($actor instanceof User, 403);

                    Gate::forUser($actor)->authorize('oauth-clients.view');

                    Validator::make($data, [
                        'grant_type' => ['required', Rule::in(['authorization_code_pkce', 'client_credentials'])],
                    ])->validate();

                    $grantType = $data['grant_type'];

                    $client = $grantType === 'client_credentials'
                        ? $this->clientManager->createClientCredentialsClient($actor, $data['name'])
                        : $this->clientManager->createPublicPkceClient($actor, $data['name'], $data['redirect_uri']);

                    return $this->downloadClientConfiguration($client, $grantType);
                }),
        ];
    }

    public function revokeClient(string $clientId): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        Gate::forUser($actor)->authorize('oauth-clients.view');

        $this->clientManager->revoke($actor, $clientId);

        Notification::make()
            ->title('OAuth client revoked')
            ->success()
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return [
            'clients' => $this->clientManager->list($actor),
        ];
    }

    private function downloadClientConfiguration(Client $client, string $grantType): StreamedResponse
    {
        $configuration = [
            'client_id' => $client->getKey(),
            'grant_type' => $grantType,
            'authorization_endpoint' => route('passport.authorizations.authorize'),
            'token_endpoint' => route('passport.token'),
            'redirect_uris' => $client->getAttribute('redirect_uris') ?? [],
        ];

        if ($client->plainSecret !== null) {
            $configuration['client_secret'] = $client->plainSecret;
        }

        $json = json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $filename = 'oauth-client-'.$client->getKey().'.json';

        return response()->streamDownload(
            static function () use ($json): void {
                echo $json;
            },
            $filename,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
