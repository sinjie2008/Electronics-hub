<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogTestDatabase;

beforeEach(function () {
    $this->catalogStorageRoot = CatalogTestDatabase::prepare();
});

afterEach(function () {
    CatalogTestDatabase::cleanup($this->catalogStorageRoot ?? null);
});

it('upgrades legacy rows and indexes on catalog while repeat migration preserves data', function () {
    CatalogTestDatabase::dropOwnedTables();
    CatalogTestDatabase::forgetMigrationRecord();

    $catalog = DB::connection('catalog');
    $catalog->unprepared(<<<'SQL'
        CREATE TABLE `category` (
            `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `parent_id` INT NULL,
            `name` VARCHAR(255) NOT NULL,
            `type` ENUM('category', 'series') NOT NULL DEFAULT 'category',
            `latex_templating_enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `display_order` INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $catalog->unprepared(<<<'SQL'
        CREATE TABLE `series_custom_field` (
            `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `series_id` INT NOT NULL,
            `field_key` VARCHAR(64) NOT NULL,
            `label` VARCHAR(255) NOT NULL,
            `field_type` ENUM('text', 'number') NOT NULL DEFAULT 'text',
            `sort_order` INT NOT NULL DEFAULT 0,
            `is_required` TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY `idx_series_field_key` (`series_id`, `field_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $catalog->unprepared(<<<'SQL'
        CREATE TABLE `latex_templates` (
            `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(255) NOT NULL,
            `latex_content` MEDIUMTEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $catalog->unprepared(<<<'SQL'
        CREATE TABLE `latex_variables` (
            `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `field_key` VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    $catalog->unprepared(<<<'SQL'
        CREATE TABLE `global_variables` (
            `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `key_name` VARCHAR(255) NOT NULL,
            `type` VARCHAR(50) NOT NULL DEFAULT 'text',
            `value` TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    $catalog->table('category')->insert([
        ['id' => 101, 'parent_id' => null, 'name' => 'Legacy Components', 'type' => 'category', 'latex_templating_enabled' => 0, 'display_order' => 1],
        ['id' => 102, 'parent_id' => 101, 'name' => 'Legacy Series', 'type' => 'series', 'latex_templating_enabled' => 1, 'display_order' => 1],
    ]);
    $catalog->table('series_custom_field')->insert([
        'id' => 201,
        'series_id' => 102,
        'field_key' => 'capacity',
        'label' => 'Capacity',
        'field_type' => 'text',
        'sort_order' => 1,
        'is_required' => 0,
    ]);
    $catalog->table('latex_templates')->insert([
        'id' => 301,
        'title' => 'Legacy LaTeX API template',
        'latex_content' => '\\documentclass{article}',
    ]);
    $catalog->table('global_variables')->insert([
        'id' => 401,
        'key_name' => 'global_customer',
        'type' => 'text',
        'value' => 'Electronics Hub',
    ]);

    CatalogTestDatabase::migrate();

    expect((int) $catalog->table('category')->where('id', 102)->value('typst_templating_enabled'))
        ->toBe(1)
        ->and($catalog->table('series_custom_field')->where('id', 201)->value('field_scope'))
        ->toBe('product_attribute')
        ->and($catalog->table('latex_templates')->where('id', 301)->value('latex_code'))
        ->toBe('\\documentclass{article}')
        ->and($catalog->table('latex_templates')->where('id', 301)->value('latex_content'))
        ->toBe('\\documentclass{article}')
        ->and($catalog->table('latex_variables')->where('id', 401)->value('field_key'))
        ->toBe('global_customer')
        ->and($catalog->table('latex_variables')->where('id', 401)->value('field_value'))
        ->toBe('Electronics Hub');

    $index = $catalog->select(
        'SELECT COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
         ORDER BY seq_in_index',
        ['series_custom_field', 'idx_series_field_key']
    );
    expect(collect($index)->pluck('column_name')->all())
        ->toBe(['series_id', 'field_scope', 'field_key'])
        ->and(collect($index)->pluck('non_unique')->map(fn ($value): int => (int) $value)->unique()->all())
        ->toBe([0]);

    $catalog->table('series_custom_field')->insert([
        'series_id' => 102,
        'field_key' => 'capacity',
        'label' => 'Series capacity label',
        'field_type' => 'text',
        'field_scope' => 'series_metadata',
        'sort_order' => 2,
        'is_required' => 0,
    ]);
    expect($catalog->table('series_custom_field')
        ->where('series_id', 102)->where('field_key', 'capacity')->count())->toBe(2);

    $beforeRepeat = [
        'categories' => $catalog->table('category')->count(),
        'fields' => $catalog->table('series_custom_field')->count(),
        'seriesValues' => $catalog->table('series_custom_field_value')->count(),
        'latexVariables' => $catalog->table('latex_variables')->count(),
    ];

    CatalogTestDatabase::forgetMigrationRecord();
    CatalogTestDatabase::migrate();

    expect([
        'categories' => $catalog->table('category')->count(),
        'fields' => $catalog->table('series_custom_field')->count(),
        'seriesValues' => $catalog->table('series_custom_field_value')->count(),
        'latexVariables' => $catalog->table('latex_variables')->count(),
    ])->toBe($beforeRepeat)
        ->and($catalog->table('latex_variables')->where('field_key', 'global_customer')->count())
        ->toBe(1)
        ->and($catalog->table('series_custom_field')->where('series_id', 102)
            ->where('field_scope', 'series_metadata')->whereIn('field_key', ['series_voltage', 'series_notes'])
            ->count())->toBe(2);

    $catalogMigrationRecorded = $catalog->table('migrations')
        ->where('migration', '2026_10_01_000000_create_catalog_schema')->exists();
    $hostMigrationRecorded = DB::connection()->table('migrations')
        ->where('migration', '2026_10_01_000000_create_catalog_schema')->exists();
    expect($catalogMigrationRecorded)->toBeTrue()
        ->and($hostMigrationRecorded)->toBeFalse();
});
