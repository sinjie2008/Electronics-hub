<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create missing Catalog tables and move known legacy layouts forward without
     * removing columns or rows that older installations may still use.
     */
    public function up(): void
    {
        $connection = $this->catalogConnection();

        $this->preflightExistingTables($connection);

        foreach ($this->createTableStatements() as $statement) {
            $connection->statement($statement);
        }

        $this->upgradeCoreTables($connection);
        $this->upgradeLatexApiTables($connection);
    }

    /**
     * The migration supports existing installations, so rollback must not drop
     * tables or data that may have existed before this migration ran.
     */
    public function down(): void {}

    private function catalogConnection(): Connection
    {
        $connectionName = config('catalog.connection') ?: config('database.default');

        return DB::connection((string) $connectionName);
    }

    /**
     * Validate every existing same-name table before the first CREATE/ALTER.
     * This keeps a host-table collision from leaving a partially-created Catalog
     * schema behind when a later table turns out to be incompatible.
     */
    private function preflightExistingTables(Connection $connection): void
    {
        $requirements = [
            'category' => ['id', 'parent_id', 'name', 'type', 'display_order'],
            'product' => ['id', 'series_id', 'sku', 'name', 'description'],
            'series_custom_field' => [
                'id', 'series_id', 'field_key', 'label', 'field_type', 'sort_order', 'is_required',
            ],
            'product_custom_field_value' => [
                'id', 'product_id', 'series_custom_field_id', 'value',
            ],
            'series_custom_field_value' => [
                'id', 'series_id', 'series_custom_field_id', 'value',
            ],
            'latex_template' => ['id', 'title', 'description', 'latex_source', 'pdf_path'],
            'seed_migration' => ['id', 'name', 'executed_at'],
            'typst_templates' => [
                'id', 'title', 'description', 'typst_content', 'is_global', 'series_id',
                'last_pdf_path', 'last_pdf_generated_at', 'created_at', 'updated_at',
            ],
            'typst_variables' => [
                'id', 'field_key', 'field_type', 'field_value', 'is_global', 'series_id',
                'created_at', 'updated_at',
            ],
            'typst_series_preferences' => [
                'series_id', 'last_global_template_id', 'updated_at',
            ],
            'latex_variables' => ['id', 'field_key'],
        ];

        foreach ($requirements as $table => $columns) {
            if ($this->tableExists($connection, $table)) {
                $this->assertColumns($connection, $table, $columns);
            }
        }

        $typeRequirements = [
            'category' => [
                'id' => ['int'], 'parent_id' => ['int'], 'name' => ['varchar'],
                'type' => ['enum', 'varchar'], 'display_order' => ['int'],
            ],
            'product' => [
                'id' => ['int'], 'series_id' => ['int'], 'sku' => ['varchar'],
                'name' => ['varchar'], 'description' => ['text', 'mediumtext', 'longtext'],
            ],
            'series_custom_field' => [
                'id' => ['int'], 'series_id' => ['int'], 'field_key' => ['varchar'],
                'label' => ['varchar'], 'field_type' => ['enum', 'varchar'],
                'sort_order' => ['int'], 'is_required' => ['tinyint', 'bit'],
            ],
            'product_custom_field_value' => [
                'id' => ['int'], 'product_id' => ['int'], 'series_custom_field_id' => ['int'],
                'value' => ['varchar', 'text', 'mediumtext', 'longtext'],
            ],
            'series_custom_field_value' => [
                'id' => ['int'], 'series_id' => ['int'], 'series_custom_field_id' => ['int'],
                'value' => ['varchar', 'text', 'mediumtext', 'longtext'],
            ],
            'latex_template' => [
                'id' => ['int'], 'title' => ['varchar'], 'description' => ['varchar', 'text'],
                'latex_source' => ['text', 'mediumtext', 'longtext'], 'pdf_path' => ['varchar'],
            ],
            'seed_migration' => [
                'id' => ['int'], 'name' => ['varchar'], 'executed_at' => ['timestamp', 'datetime'],
            ],
            'typst_templates' => [
                'id' => ['int'], 'title' => ['varchar'], 'description' => ['varchar', 'text'],
                'typst_content' => ['text', 'mediumtext', 'longtext'], 'is_global' => ['tinyint', 'bit'],
                'series_id' => ['int'], 'last_pdf_path' => ['varchar'],
                'last_pdf_generated_at' => ['datetime'], 'created_at' => ['datetime', 'timestamp'],
                'updated_at' => ['datetime', 'timestamp'],
            ],
            'typst_variables' => [
                'id' => ['int'], 'field_key' => ['varchar'], 'field_type' => ['varchar'],
                'field_value' => ['varchar', 'text', 'mediumtext', 'longtext'],
                'is_global' => ['tinyint', 'bit'], 'series_id' => ['int'],
                'created_at' => ['datetime', 'timestamp'], 'updated_at' => ['datetime', 'timestamp'],
            ],
            'typst_series_preferences' => [
                'series_id' => ['int'], 'last_global_template_id' => ['int'], 'updated_at' => ['datetime', 'timestamp'],
            ],
            'latex_variables' => ['id' => ['int'], 'field_key' => ['varchar']],
        ];

        foreach ($typeRequirements as $table => $columns) {
            if ($this->tableExists($connection, $table)) {
                $this->assertColumnTypes($connection, $table, $columns);
            }
        }

        if ($this->tableExists($connection, 'latex_templates')) {
            $this->assertColumns($connection, 'latex_templates', ['id', 'title']);
            $this->assertColumnTypes($connection, 'latex_templates', [
                'id' => ['int'],
                'title' => ['varchar'],
                'latex_code' => ['text', 'mediumtext', 'longtext'],
                'latex_content' => ['text', 'mediumtext', 'longtext'],
            ]);

            if (! $this->hasColumn($connection, 'latex_templates', 'latex_code')
                && ! $this->hasColumn($connection, 'latex_templates', 'latex_content')) {
                throw new RuntimeException(
                    'Catalog migration found an incompatible existing `latex_templates` table '
                    .'(missing: latex_code or latex_content); inspect the host schema before migrating.'
                );
            }
        }

        if ($this->tableExists($connection, 'global_variables')) {
            $this->assertColumns($connection, 'global_variables', ['id', 'key_name', 'type', 'value']);
            $this->assertColumnTypes($connection, 'global_variables', [
                'id' => ['int'],
                'key_name' => ['varchar'],
                'type' => ['enum', 'varchar'],
                'value' => ['varchar', 'text', 'mediumtext', 'longtext'],
            ]);
        }
    }

    /**
     * @param  array<string, list<string>>  $typeRequirements
     */
    private function assertColumnTypes(Connection $connection, string $table, array $typeRequirements): void
    {
        foreach ($typeRequirements as $column => $allowedTypes) {
            $columnInfo = $this->columnInfo($connection, $table, $column);
            if ($columnInfo === null) {
                continue;
            }

            $dataType = strtolower((string) ($columnInfo->data_type ?? ''));
            $columnType = strtolower((string) ($columnInfo->column_type ?? ''));
            if (! in_array($dataType, $allowedTypes, true)
                || (in_array('int', $allowedTypes, true) && str_contains($columnType, 'unsigned'))) {
                throw new RuntimeException(sprintf(
                    'Catalog migration found an incompatible existing `%s` table (`%s` has type `%s`); inspect the host schema before migrating.',
                    $table,
                    $column,
                    $columnType
                ));
            }

            if ($column === 'id' && ! str_contains(strtolower((string) ($columnInfo->extra ?? '')), 'auto_increment')) {
                throw new RuntimeException(sprintf(
                    'Catalog migration found an incompatible existing `%s` table (`id` is not AUTO_INCREMENT); inspect the host schema before migrating.',
                    $table
                ));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function createTableStatements(): array
    {
        return [
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS `category` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `parent_id` INT NULL,
                `name` VARCHAR(255) NOT NULL,
                `type` ENUM('category', 'series') NOT NULL DEFAULT 'category',
                `typst_templating_enabled` TINYINT(1) NOT NULL DEFAULT 0,
                `display_order` INT NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT `fk_category_parent` FOREIGN KEY (`parent_id`) REFERENCES `category` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS `product` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `series_id` INT NOT NULL,
                `sku` VARCHAR(128) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `description` TEXT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT `fk_product_series` FOREIGN KEY (`series_id`) REFERENCES `category` (`id`) ON DELETE CASCADE,
                UNIQUE KEY `idx_product_series_sku` (`series_id`, `sku`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS `series_custom_field` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `series_id` INT NOT NULL,
                `field_key` VARCHAR(64) NOT NULL,
                `label` VARCHAR(255) NOT NULL,
                `field_type` ENUM('text','number','file') NOT NULL DEFAULT 'text',
                `field_scope` ENUM('series_metadata', 'product_attribute') NOT NULL DEFAULT 'product_attribute',
                `default_value` TEXT NULL,
                `sort_order` INT NOT NULL DEFAULT 0,
                `is_required` TINYINT(1) NOT NULL DEFAULT 0,
                `is_public_portal_hidden` TINYINT(1) NOT NULL DEFAULT 0,
                `is_backend_portal_hidden` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT `fk_series_custom_field_series` FOREIGN KEY (`series_id`) REFERENCES `category` (`id`) ON DELETE CASCADE,
                UNIQUE KEY `idx_series_field_key` (`series_id`, `field_scope`, `field_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS `product_custom_field_value` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `product_id` INT NOT NULL,
                `series_custom_field_id` INT NOT NULL,
                `value` TEXT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT `fk_product_custom_field_product` FOREIGN KEY (`product_id`) REFERENCES `product` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_product_custom_field_series_field` FOREIGN KEY (`series_custom_field_id`) REFERENCES `series_custom_field` (`id`) ON DELETE CASCADE,
                UNIQUE KEY `idx_product_field_unique` (`product_id`, `series_custom_field_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS `series_custom_field_value` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `series_id` INT NOT NULL,
                `series_custom_field_id` INT NOT NULL,
                `value` TEXT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT `fk_series_value_series` FOREIGN KEY (`series_id`) REFERENCES `category` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_series_value_field` FOREIGN KEY (`series_custom_field_id`) REFERENCES `series_custom_field` (`id`) ON DELETE CASCADE,
                UNIQUE KEY `idx_series_value_unique` (`series_id`, `series_custom_field_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS `latex_template` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `title` VARCHAR(255) NOT NULL,
                `description` TEXT NULL,
                `latex_source` LONGTEXT NOT NULL,
                `pdf_path` VARCHAR(512) NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS `seed_migration` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(255) NOT NULL,
                `executed_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `idx_seed_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS `typst_templates` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `title` VARCHAR(255) NOT NULL,
                `description` TEXT NULL,
                `typst_content` MEDIUMTEXT NULL,
                `is_global` TINYINT(1) NULL DEFAULT 0,
                `series_id` INT NULL DEFAULT NULL,
                `last_pdf_path` VARCHAR(255) NULL DEFAULT NULL,
                `last_pdf_generated_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS `typst_variables` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `field_key` VARCHAR(255) NOT NULL,
                `field_type` VARCHAR(50) NOT NULL DEFAULT 'text',
                `field_value` TEXT NULL,
                `is_global` TINYINT(1) NULL DEFAULT 0,
                `series_id` INT NULL DEFAULT NULL,
                `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS `typst_series_preferences` (
                `series_id` INT NOT NULL PRIMARY KEY,
                `last_global_template_id` INT NULL DEFAULT NULL,
                `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT `fk_series_pref_template` FOREIGN KEY (`last_global_template_id`) REFERENCES `typst_templates` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS `latex_templates` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `title` VARCHAR(255) NOT NULL,
                `description` TEXT NULL,
                `latex_code` MEDIUMTEXT NULL,
                `is_global` TINYINT(1) NOT NULL DEFAULT 0,
                `series_id` INT NULL DEFAULT NULL,
                `last_pdf_path` VARCHAR(255) NULL DEFAULT NULL,
                `last_pdf_generated_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS `latex_variables` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `field_key` VARCHAR(255) NOT NULL,
                `field_type` VARCHAR(50) NOT NULL DEFAULT 'text',
                `field_value` TEXT NULL,
                `is_global` TINYINT(1) NOT NULL DEFAULT 0,
                `series_id` INT NULL DEFAULT NULL,
                `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL,
        ];
    }

    private function upgradeCoreTables(Connection $connection): void
    {
        $this->assertColumns($connection, 'category', ['id', 'parent_id', 'name', 'type', 'display_order']);
        $this->assertColumns($connection, 'product', ['id', 'series_id', 'sku', 'name']);
        $this->assertColumns($connection, 'series_custom_field', [
            'id', 'series_id', 'field_key', 'label', 'field_type', 'sort_order', 'is_required',
        ]);
        $this->assertColumns($connection, 'product_custom_field_value', [
            'id', 'product_id', 'series_custom_field_id', 'value',
        ]);
        $this->assertColumns($connection, 'series_custom_field_value', [
            'id', 'series_id', 'series_custom_field_id', 'value',
        ]);
        $this->assertColumns($connection, 'latex_template', [
            'id', 'title', 'description', 'latex_source', 'pdf_path',
        ]);
        $this->assertColumns($connection, 'seed_migration', ['id', 'name', 'executed_at']);
        $this->assertColumns($connection, 'typst_templates', [
            'id', 'title', 'description', 'typst_content', 'is_global', 'series_id',
            'last_pdf_path', 'last_pdf_generated_at', 'created_at', 'updated_at',
        ]);
        $this->assertColumns($connection, 'typst_variables', [
            'id', 'field_key', 'field_type', 'field_value', 'is_global', 'series_id', 'created_at', 'updated_at',
        ]);
        $this->assertColumns($connection, 'typst_series_preferences', [
            'series_id', 'last_global_template_id', 'updated_at',
        ]);

        $this->addColumnIfMissing(
            $connection,
            'category',
            'typst_templating_enabled',
            '`typst_templating_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `type`'
        );
        if ($this->hasColumn($connection, 'category', 'latex_templating_enabled')) {
            $connection->statement(
                'UPDATE `category` SET `typst_templating_enabled` = `latex_templating_enabled`
                 WHERE `typst_templating_enabled` IS NULL OR `typst_templating_enabled` = 0'
            );
        }

        $this->addColumnIfMissing(
            $connection,
            'series_custom_field',
            'field_scope',
            "`field_scope` ENUM('series_metadata', 'product_attribute') NOT NULL DEFAULT 'product_attribute' AFTER `field_type`"
        );
        $this->addColumnIfMissing(
            $connection,
            'series_custom_field',
            'default_value',
            '`default_value` TEXT NULL AFTER `field_scope`'
        );
        $this->addColumnIfMissing(
            $connection,
            'series_custom_field',
            'is_public_portal_hidden',
            '`is_public_portal_hidden` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_required`'
        );
        $this->addColumnIfMissing(
            $connection,
            'series_custom_field',
            'is_backend_portal_hidden',
            '`is_backend_portal_hidden` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_public_portal_hidden`'
        );

        $this->ensureEnumValues(
            $connection,
            'series_custom_field',
            'field_type',
            ['text', 'number', 'file'],
            "ENUM('text','number','file') NOT NULL DEFAULT 'text'"
        );
        $this->ensureEnumValues(
            $connection,
            'series_custom_field',
            'field_scope',
            ['series_metadata', 'product_attribute'],
            "ENUM('series_metadata','product_attribute') NOT NULL DEFAULT 'product_attribute'",
            true
        );
        $connection->statement(
            "UPDATE `series_custom_field` SET `field_scope` = 'product_attribute'
             WHERE `field_scope` IS NULL OR `field_scope` = ''"
        );

        $this->ensureUniqueIndex($connection, 'product', 'idx_product_series_sku', ['series_id', 'sku']);
        $this->ensureUniqueIndex(
            $connection,
            'series_custom_field',
            'idx_series_field_key',
            ['series_id', 'field_scope', 'field_key']
        );
        $this->ensureUniqueIndex(
            $connection,
            'product_custom_field_value',
            'idx_product_field_unique',
            ['product_id', 'series_custom_field_id']
        );
        $this->ensureUniqueIndex(
            $connection,
            'series_custom_field_value',
            'idx_series_value_unique',
            ['series_id', 'series_custom_field_id']
        );
        $this->ensureUniqueIndex($connection, 'seed_migration', 'idx_seed_name', ['name']);

        $this->ensureDefaultMetadataFields($connection);
    }

    private function upgradeLatexApiTables(Connection $connection): void
    {
        $this->assertColumns($connection, 'latex_templates', ['id', 'title']);

        $hasLegacyCode = $this->hasColumn($connection, 'latex_templates', 'latex_content');
        $hasApiCode = $this->hasColumn($connection, 'latex_templates', 'latex_code');
        if (! $hasLegacyCode && ! $hasApiCode) {
            throw new RuntimeException(
                'Catalog migration found an existing latex_templates table without latex_code or latex_content; inspect the host schema before migrating.'
            );
        }

        $this->addColumnIfMissing($connection, 'latex_templates', 'description', '`description` TEXT NULL AFTER `title`');
        $this->addColumnIfMissing(
            $connection,
            'latex_templates',
            'latex_code',
            '`latex_code` MEDIUMTEXT NULL AFTER `description`'
        );
        if ($hasLegacyCode) {
            $connection->statement(<<<'SQL'
                UPDATE `latex_templates` SET `latex_code` = `latex_content`
                WHERE `latex_content` IS NOT NULL AND (`latex_code` IS NULL OR `latex_code` = '')
                SQL
            );
        }

        $globalAdded = $this->addColumnIfMissing(
            $connection,
            'latex_templates',
            'is_global',
            '`is_global` TINYINT(1) NOT NULL DEFAULT 0 AFTER `latex_code`'
        );
        $this->addColumnIfMissing(
            $connection,
            'latex_templates',
            'series_id',
            '`series_id` INT NULL DEFAULT NULL AFTER `is_global`'
        );
        $this->addColumnIfMissing(
            $connection,
            'latex_templates',
            'last_pdf_path',
            '`last_pdf_path` VARCHAR(255) NULL DEFAULT NULL AFTER `series_id`'
        );
        $this->addColumnIfMissing(
            $connection,
            'latex_templates',
            'last_pdf_generated_at',
            '`last_pdf_generated_at` DATETIME NULL DEFAULT NULL AFTER `last_pdf_path`'
        );
        $this->addColumnIfMissing(
            $connection,
            'latex_templates',
            'created_at',
            '`created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP'
        );
        $this->addColumnIfMissing(
            $connection,
            'latex_templates',
            'updated_at',
            '`updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );

        if ($globalAdded) {
            $connection->statement(
                'UPDATE `latex_templates` SET `is_global` = CASE
                    WHEN `series_id` IS NULL OR `series_id` = 0 THEN 1 ELSE 0 END'
            );
        }

        $this->assertColumns($connection, 'latex_templates', [
            'id', 'title', 'description', 'latex_code', 'is_global', 'series_id',
            'last_pdf_path', 'last_pdf_generated_at', 'created_at', 'updated_at',
        ]);

        $this->assertColumns($connection, 'latex_variables', ['id', 'field_key']);
        $variablesGlobalAdded = $this->addColumnIfMissing(
            $connection,
            'latex_variables',
            'is_global',
            '`is_global` TINYINT(1) NOT NULL DEFAULT 0'
        );
        $this->addColumnIfMissing(
            $connection,
            'latex_variables',
            'field_type',
            "`field_type` VARCHAR(50) NOT NULL DEFAULT 'text' AFTER `field_key`"
        );
        $this->addColumnIfMissing(
            $connection,
            'latex_variables',
            'field_value',
            '`field_value` TEXT NULL AFTER `field_type`'
        );
        $this->addColumnIfMissing(
            $connection,
            'latex_variables',
            'series_id',
            '`series_id` INT NULL DEFAULT NULL AFTER `is_global`'
        );
        $this->addColumnIfMissing(
            $connection,
            'latex_variables',
            'created_at',
            '`created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP'
        );
        $this->addColumnIfMissing(
            $connection,
            'latex_variables',
            'updated_at',
            '`updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );
        if ($variablesGlobalAdded) {
            $connection->statement(
                'UPDATE `latex_variables` SET `is_global` = 1
                 WHERE `series_id` IS NULL OR `series_id` = 0'
            );
        }

        $this->assertColumns($connection, 'latex_variables', [
            'id', 'field_key', 'field_type', 'field_value', 'is_global', 'series_id', 'created_at', 'updated_at',
        ]);

        $this->importLegacyGlobalVariables($connection);
    }

    private function ensureDefaultMetadataFields(Connection $connection): void
    {
        $fields = [
            ['field_key' => 'series_voltage', 'label' => 'Voltage Range'],
            ['field_key' => 'series_notes', 'label' => 'Series Notes'],
        ];

        $connection->transaction(function () use ($connection, $fields): void {
            $seriesRows = $connection->table('category')
                ->select('id')
                ->where('type', 'series')
                ->orderBy('id')
                ->get();

            foreach ($seriesRows as $seriesRow) {
                $seriesId = (int) $seriesRow->id;
                $maximumOrder = (int) ($connection->table('series_custom_field')
                    ->where('series_id', $seriesId)
                    ->where('field_scope', 'series_metadata')
                    ->max('sort_order') ?? 0);

                foreach ($fields as $index => $field) {
                    $fieldId = $connection->table('series_custom_field')
                        ->where('series_id', $seriesId)
                        ->where('field_scope', 'series_metadata')
                        ->where('field_key', $field['field_key'])
                        ->value('id');

                    if ($fieldId === null) {
                        $fieldId = $connection->table('series_custom_field')->insertGetId([
                            'series_id' => $seriesId,
                            'field_key' => $field['field_key'],
                            'label' => $field['label'],
                            'field_type' => 'text',
                            'field_scope' => 'series_metadata',
                            'default_value' => null,
                            'sort_order' => $maximumOrder + $index + 1,
                            'is_required' => 0,
                            'is_public_portal_hidden' => 0,
                            'is_backend_portal_hidden' => 0,
                        ]);
                    }

                    $valueExists = $connection->table('series_custom_field_value')
                        ->where('series_id', $seriesId)
                        ->where('series_custom_field_id', (int) $fieldId)
                        ->exists();

                    if (! $valueExists) {
                        $connection->table('series_custom_field_value')->insert([
                            'series_id' => $seriesId,
                            'series_custom_field_id' => (int) $fieldId,
                            'value' => null,
                        ]);
                    }
                }
            }
        });
    }

    private function importLegacyGlobalVariables(Connection $connection): void
    {
        if (! $this->tableExists($connection, 'global_variables')) {
            return;
        }

        $this->assertColumns($connection, 'global_variables', ['id', 'key_name', 'type', 'value']);

        $columns = ['id', 'key_name', 'type', 'value'];
        foreach (['created_at', 'updated_at'] as $timestampColumn) {
            if ($this->hasColumn($connection, 'global_variables', $timestampColumn)) {
                $columns[] = $timestampColumn;
            }
        }

        foreach ($connection->table('global_variables')->select($columns)->orderBy('id')->cursor() as $legacyVariable) {
            $alreadyImported = $connection->table('latex_variables')
                ->where('field_key', (string) $legacyVariable->key_name)
                ->where('is_global', 1)
                ->where(function ($query): void {
                    $query->whereNull('series_id')->orWhere('series_id', 0);
                })
                ->exists();

            if ($alreadyImported) {
                continue;
            }

            $insert = [
                'field_key' => (string) $legacyVariable->key_name,
                'field_type' => (string) $legacyVariable->type,
                'field_value' => $legacyVariable->value,
                'is_global' => 1,
                'series_id' => null,
                'created_at' => $legacyVariable->created_at ?? null,
                'updated_at' => $legacyVariable->updated_at ?? null,
            ];

            $legacyId = (int) $legacyVariable->id;
            if (! $connection->table('latex_variables')->where('id', $legacyId)->exists()) {
                $insert['id'] = $legacyId;
            }

            $connection->table('latex_variables')->insert($insert);
        }
    }

    private function ensureUniqueIndex(
        Connection $connection,
        string $table,
        string $indexName,
        array $columns
    ): void {
        $indexes = $this->indexesFor($connection, $table);
        $target = $indexes[$indexName] ?? null;
        if ($target !== null && $target['unique'] && $target['columns'] === $columns) {
            return;
        }

        $matchingUniqueIndexExists = false;
        foreach ($indexes as $index) {
            if ($index['unique'] && $index['columns'] === $columns) {
                $matchingUniqueIndexExists = true;
                break;
            }
        }

        if ($target === null && $matchingUniqueIndexExists) {
            $temporaryIndexName = $indexName.'_migration_tmp';
            $temporaryIndex = $indexes[$temporaryIndexName] ?? null;
            if ($temporaryIndex !== null) {
                if (! $temporaryIndex['unique'] || $temporaryIndex['columns'] !== $columns) {
                    throw new RuntimeException(sprintf(
                        'Catalog migration found an incompatible temporary index `%s.%s`; inspect the host schema before migrating.',
                        $table,
                        $temporaryIndexName
                    ));
                }
                $connection->statement(sprintf(
                    'ALTER TABLE `%s` RENAME INDEX `%s` TO `%s`',
                    $table,
                    $temporaryIndexName,
                    $indexName
                ));
            }

            return;
        }

        $this->assertNoDuplicateRows($connection, $table, $columns);
        $quotedColumns = implode(', ', array_map(static fn (string $column): string => '`'.$column.'`', $columns));

        if ($target !== null) {
            $temporaryIndexName = $indexName.'_migration_tmp';
            $temporaryIndex = $indexes[$temporaryIndexName] ?? null;
            if ($temporaryIndex !== null && (! $temporaryIndex['unique'] || $temporaryIndex['columns'] !== $columns)) {
                throw new RuntimeException(sprintf(
                    'Catalog migration found an incompatible temporary index `%s.%s`; inspect the host schema before migrating.',
                    $table,
                    $temporaryIndexName
                ));
            }

            if ($temporaryIndex === null) {
                $connection->statement(sprintf(
                    'ALTER TABLE `%s` ADD UNIQUE KEY `%s` (%s)',
                    $table,
                    $temporaryIndexName,
                    $quotedColumns
                ));
            }

            $connection->statement(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $table, $indexName));
            $connection->statement(sprintf(
                'ALTER TABLE `%s` RENAME INDEX `%s` TO `%s`',
                $table,
                $temporaryIndexName,
                $indexName
            ));

            return;
        }

        $connection->statement(
            sprintf('ALTER TABLE `%s` ADD UNIQUE KEY `%s` (%s)', $table, $indexName, $quotedColumns)
        );
    }

    private function ensureEnumValues(
        Connection $connection,
        string $table,
        string $column,
        array $allowedValues,
        string $definition,
        bool $allowEmptyString = false
    ): void {
        $columnInfo = $this->columnInfo($connection, $table, $column);
        if ($columnInfo === null) {
            throw new RuntimeException(sprintf('Catalog migration expected %s.%s to exist.', $table, $column));
        }

        $columnType = strtolower((string) ($columnInfo->column_type ?? $columnInfo->COLUMN_TYPE ?? ''));
        $hasAllValues = true;
        foreach ($allowedValues as $allowedValue) {
            if (! str_contains($columnType, "'".strtolower($allowedValue)."'")) {
                $hasAllValues = false;
                break;
            }
        }

        if ($hasAllValues) {
            return;
        }

        $allowedSql = implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $allowedValues
        ));
        $emptyCondition = $allowEmptyString ? sprintf(" AND `%s` <> ''", $column) : '';
        $invalidValue = $connection->selectOne(sprintf(
            'SELECT 1 AS invalid_value FROM `%s` WHERE `%s` IS NOT NULL%s AND `%s` NOT IN (%s) LIMIT 1',
            $table,
            $column,
            $emptyCondition,
            $column,
            $allowedSql
        ));
        if ($invalidValue !== null) {
            throw new RuntimeException(sprintf(
                'Catalog migration found unsupported values in %s.%s; resolve them before changing its enum.',
                $table,
                $column
            ));
        }

        $connection->statement(sprintf('ALTER TABLE `%s` MODIFY `%s` %s', $table, $column, $definition));
    }

    private function assertNoDuplicateRows(Connection $connection, string $table, array $columns): void
    {
        $quotedColumns = implode(', ', array_map(static fn (string $column): string => '`'.$column.'`', $columns));
        $duplicate = $connection->selectOne(
            sprintf(
                'SELECT 1 AS duplicate_row FROM `%s` GROUP BY %s HAVING COUNT(*) > 1 LIMIT 1',
                $table,
                $quotedColumns
            )
        );

        if ($duplicate !== null) {
            throw new RuntimeException(sprintf(
                'Catalog migration cannot add unique index on %s (%s) because duplicate rows exist; preserve and resolve those rows before migrating.',
                $table,
                implode(', ', $columns)
            ));
        }
    }

    /**
     * @return array<string, array{unique: bool, columns: list<string>}>
     */
    private function indexesFor(Connection $connection, string $table): array
    {
        $rows = $connection->select(
            'SELECT INDEX_NAME AS index_name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS sequence_number,
                    COLUMN_NAME AS column_name
             FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$table]
        );

        $indexes = [];
        foreach ($rows as $row) {
            $indexName = (string) $row->index_name;
            $indexes[$indexName] ??= ['unique' => (int) $row->non_unique === 0, 'columns' => []];
            $indexes[$indexName]['columns'][] = (string) $row->column_name;
        }

        return $indexes;
    }

    private function addColumnIfMissing(
        Connection $connection,
        string $table,
        string $column,
        string $definition
    ): bool {
        if ($this->hasColumn($connection, $table, $column)) {
            return false;
        }

        $connection->statement(sprintf('ALTER TABLE `%s` ADD COLUMN %s', $table, $definition));

        return true;
    }

    private function assertColumns(Connection $connection, string $table, array $columns): void
    {
        if (! $this->tableExists($connection, $table)) {
            throw new RuntimeException(sprintf('Catalog migration expected table `%s` to exist.', $table));
        }

        $missing = array_values(array_filter(
            $columns,
            fn (string $column): bool => ! $this->hasColumn($connection, $table, $column)
        ));
        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'Catalog migration found an incompatible existing `%s` table (missing: %s); inspect the host schema before migrating.',
                $table,
                implode(', ', $missing)
            ));
        }
    }

    private function tableExists(Connection $connection, string $table): bool
    {
        return $connection->selectOne(
            'SELECT 1 AS table_exists FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1',
            [$table]
        ) !== null;
    }

    private function hasColumn(Connection $connection, string $table, string $column): bool
    {
        return $this->columnInfo($connection, $table, $column) !== null;
    }

    private function columnInfo(Connection $connection, string $table, string $column): ?object
    {
        return $connection->selectOne(
            'SELECT column_name AS column_name, column_type AS column_type,
                    data_type AS data_type, extra AS extra
             FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
             LIMIT 1',
            [$table, $column]
        );
    }
};
