<?php

declare(strict_types=1);

namespace Modules\System\Providers;

use Filament\Tables\Table;
use Illuminate\Support\ServiceProvider;
use Modules\System\Settings\SystemSettings;

class SystemServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'system');

        Table::configureUsing(function (Table $table): void {
            $table
                ->paginationPageOptions(fn (): array => array_values(array_unique([app(SystemSettings::class)->pagination_size, 10, 25, 50, 100])))
                ->defaultPaginationPageOption(fn (): int => app(SystemSettings::class)->pagination_size);
        });
    }
}
