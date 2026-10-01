<x-filament-panels::page>
    <x-filament::section heading="Installed modules" description="Install modules through your deployment process. After changing module state, rebuild deployment caches and restart long-lived workers.">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b"><th class="p-3">Module</th><th class="p-3">Version</th><th class="p-3">Status</th><th class="p-3">Dependencies</th><th class="p-3">Actions</th></tr></thead>
                <tbody>
                    @foreach ($modules as $module)
                        <tr class="border-b" wire:key="module-{{ $module['name'] }}">
                            <td class="p-3"><strong>{{ $module['name'] }}</strong><p>{{ $module['description'] }}</p>@if ($module['protected'])<x-filament::badge color="gray">Protected system module</x-filament::badge>@endif</td>
                            <td class="p-3">{{ $module['version'] }}</td>
                            <td class="p-3"><x-filament::badge :color="$module['enabled'] ? 'success' : 'gray'">{{ $module['enabled'] ? 'Enabled' : 'Disabled' }}</x-filament::badge></td>
                            <td class="p-3">{{ implode(', ', $module['dependencies']) ?: 'None' }}</td>
                            <td class="p-3">
                                <x-filament::button size="sm" color="gray" wire:click="viewModule('{{ $module['name'] }}')">View</x-filament::button>
                                @if (! $module['protected'])
                                    @if ($module['enabled'])
                                        @can('modules.disable')<x-filament::button size="sm" color="warning" wire:confirm="Disable this module? Rebuild deployment caches afterward." wire:click="disableModule('{{ $module['name'] }}')">Disable</x-filament::button>@endcan
                                    @else
                                        @can('modules.enable')<x-filament::button size="sm" wire:confirm="Enable this module? Rebuild deployment caches afterward." wire:click="enableModule('{{ $module['name'] }}')">Enable</x-filament::button>@endcan
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
    @if ($selectedModule)
        <x-filament::section :heading="$selectedModule['name']">
            <p>{{ $selectedModule['description'] }}</p>
            <p>Path: <code>{{ $selectedModule['path'] }}</code></p>
            <p>Version: {{ $selectedModule['version'] }}</p>
            <p>Dependencies: {{ implode(', ', $selectedModule['dependencies']) ?: 'None' }}</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
