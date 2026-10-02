<x-filament-panels::page>
    <iframe
        src="{{ $this->getCatalogContentUrl() }}"
        title="{{ $this->getTitle() }}"
        class="w-full rounded-xl border-0"
        style="height: calc(100dvh - 10rem); min-height: 40rem;"
        wire:ignore
    ></iframe>
</x-filament-panels::page>
