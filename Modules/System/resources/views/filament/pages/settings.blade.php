<x-filament-panels::page>
    <div class="max-w-4xl space-y-6">
        {{ $this->form }}

        @can('settings.update')
            <div class="flex justify-end">
                <x-filament::button wire:click="save" wire:target="save">
                    Save settings
                </x-filament::button>
            </div>
        @endcan
    </div>
</x-filament-panels::page>
