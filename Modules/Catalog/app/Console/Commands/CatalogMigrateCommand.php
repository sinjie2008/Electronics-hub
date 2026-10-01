<?php

declare(strict_types=1);

namespace Modules\Catalog\Console\Commands;

use Modules\Catalog\Database\Seeders\CatalogDatabaseSeeder;
use Nwidart\Modules\Commands\Database\MigrateCommand;
use RuntimeException;

/** Keep nWidart's public command while isolating Catalog's database and ledger. */
class CatalogMigrateCommand extends MigrateCommand
{
    public function executeAction($name): void
    {
        if (strcasecmp((string) $name, 'Catalog') !== 0) {
            if ($this->option('database') === config('catalog.connection', 'catalog')) {
                throw new RuntimeException('The Catalog connection is reserved for Catalog module migrations.');
            }
            parent::executeAction($name);

            return;
        }
        $module = $this->getModuleModel($name);
        if (! $module->isEnabled()) {
            throw new RuntimeException('Enable Catalog before running its migrations.');
        }
        $connection = (string) config('catalog.connection', 'catalog');
        if ($this->option('database') !== null && $this->option('database') !== $connection) {
            throw new RuntimeException('Catalog migration --database must match catalog.connection.');
        }
        $path = $module->getExtraPath((string) config('modules.paths.generator.migration.path'));
        $subpath = $this->option('subpath');
        if (is_string($subpath) && $subpath !== '') {
            if (basename($subpath) !== $subpath || ! str_ends_with($subpath, '.php')) {
                throw new RuntimeException('Catalog migration subpath must be a migration filename.');
            }
            $path .= '/'.$subpath;
        }
        $status = $this->call('migrate', [
            '--path' => [$path], '--database' => $connection, '--realpath' => true,
            '--pretend' => (bool) $this->option('pretend'), '--force' => (bool) $this->option('force'),
        ]);
        if ($status !== 0) {
            throw new RuntimeException('Catalog migrations failed; inspect the migration output.');
        }
        if ($this->option('seed') && ! $this->option('pretend')) {
            $this->call('db:seed', [
                '--class' => CatalogDatabaseSeeder::class,
                '--database' => $connection, '--force' => (bool) $this->option('force'),
            ]);
        }
    }
}
