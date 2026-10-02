<x-filament-panels::page>
    <div
        class="catalog-workspace"
        data-catalog-page="{{ $this->getCatalogPageKey() }}"
        x-load
        x-load-src="{{ \Illuminate\Support\Facades\Vite::asset('Modules/Catalog/resources/assets/js/catalog-workspace.js') }}"
        x-data="catalogWorkspace({{ \Illuminate\Support\Js::from($this->getWorkspaceConfiguration()) }})"
        wire:ignore
    >
        <div class="catalog-initialization-error hidden" role="alert" data-catalog-error></div>
        @include($this->getCatalogView())
    </div>
</x-filament-panels::page>
