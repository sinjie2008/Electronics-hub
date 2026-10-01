<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Public clients use Authorization Code with PKCE and never receive a client secret. Machine clients use
                Client Credentials. Credentials are downloaded once at creation and cannot be shown again.
            </p>
        </x-filament::section>

        <div class="space-y-4">
            @forelse ($clients as $client)
                <x-filament::section :heading="$client->name">
                    <div class="grid gap-4 text-sm md:grid-cols-2">
                        <div>
                            <div class="font-medium text-gray-950 dark:text-white">Client ID</div>
                            <code class="break-all text-gray-600 dark:text-gray-300">{{ $client->getKey() }}</code>
                        </div>
                        <div>
                            <div class="font-medium text-gray-950 dark:text-white">Grant types</div>
                            <span class="text-gray-600 dark:text-gray-300">{{ implode(', ', $client->grant_types ?? []) }}</span>
                        </div>
                        <div>
                            <div class="font-medium text-gray-950 dark:text-white">Redirect URIs</div>
                            @forelse ($client->redirect_uris ?? [] as $redirectUri)
                                <div class="break-all text-gray-600 dark:text-gray-300">{{ $redirectUri }}</div>
                            @empty
                                <span class="text-gray-500">None</span>
                            @endforelse
                        </div>
                        <div>
                            <div class="font-medium text-gray-950 dark:text-white">Created</div>
                            <time datetime="{{ $client->created_at?->toIso8601String() }}" class="text-gray-600 dark:text-gray-300">
                                {{ $client->created_at?->toDayDateTimeString() }}
                            </time>
                        </div>
                        <div>
                            <div class="font-medium text-gray-950 dark:text-white">Status</div>
                            @if ($client->revoked)
                                <x-filament::badge color="gray">Revoked</x-filament::badge>
                            @else
                                <x-filament::badge color="success">Active</x-filament::badge>
                            @endif
                        </div>
                    </div>

                    @if (! $client->revoked && auth()->user()->can('oauth-clients.revoke'))
                        <x-slot name="footer">
                            <x-filament::button
                                color="danger"
                                size="sm"
                                wire:confirm="Revoke this client and all of its access and refresh tokens?"
                                wire:click="revokeClient('{{ $client->getKey() }}')"
                            >
                                Revoke client
                            </x-filament::button>
                        </x-slot>
                    @endif
                </x-filament::section>
            @empty
                <x-filament::section>
                    <p class="text-sm text-gray-600 dark:text-gray-300">No OAuth clients have been created.</p>
                </x-filament::section>
            @endforelse
        </div>

        {{ $clients->links() }}
    </div>
</x-filament-panels::page>
