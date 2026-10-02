<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the legacy Catalog schema and its independent LaTeX API tables
     * without replacing an existing clone's tables or data.
     *
     * @var list<string>
     */
    private const CREATE_TABLES = [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS `category` (
  `id` int NOT NULL AUTO_INCREMENT,
  `parent_id` int DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `type` enum('category','series') NOT NULL DEFAULT 'category',
  `typst_templating_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `latex_templating_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `display_order` int NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_category_parent` (`parent_id`),
  CONSTRAINT `fk_category_parent` FOREIGN KEY (`parent_id`) REFERENCES `category` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS `product` (
  `id` int NOT NULL AUTO_INCREMENT,
  `series_id` int NOT NULL,
  `sku` varchar(128) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_product_series_sku` (`series_id`,`sku`),
  CONSTRAINT `fk_product_series` FOREIGN KEY (`series_id`) REFERENCES `category` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS `series_custom_field` (
  `id` int NOT NULL AUTO_INCREMENT,
  `series_id` int NOT NULL,
  `field_key` varchar(64) NOT NULL,
  `label` varchar(255) NOT NULL,
  `field_type` enum('text','number','file') NOT NULL DEFAULT 'text',
  `field_scope` enum('series_metadata','product_attribute') NOT NULL DEFAULT 'product_attribute',
  `default_value` text DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `is_public_portal_hidden` tinyint(1) NOT NULL DEFAULT 0,
  `is_backend_portal_hidden` tinyint(1) NOT NULL DEFAULT 0,
  `is_public_hidden` tinyint(1) NOT NULL DEFAULT 0,
  `is_backend_hidden` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_series_field_key` (`series_id`,`field_scope`,`field_key`),
  CONSTRAINT `fk_series_custom_field_series` FOREIGN KEY (`series_id`) REFERENCES `category` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS `product_custom_field_value` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `series_custom_field_id` int NOT NULL,
  `value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_product_field_unique` (`product_id`,`series_custom_field_id`),
  KEY `fk_product_custom_field_series_field` (`series_custom_field_id`),
  CONSTRAINT `fk_product_custom_field_product` FOREIGN KEY (`product_id`) REFERENCES `product` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_product_custom_field_series_field` FOREIGN KEY (`series_custom_field_id`) REFERENCES `series_custom_field` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS `series_custom_field_value` (
  `id` int NOT NULL AUTO_INCREMENT,
  `series_id` int NOT NULL,
  `series_custom_field_id` int NOT NULL,
  `value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_series_value_unique` (`series_id`,`series_custom_field_id`),
  KEY `fk_series_value_field` (`series_custom_field_id`),
  CONSTRAINT `fk_series_value_field` FOREIGN KEY (`series_custom_field_id`) REFERENCES `series_custom_field` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_series_value_series` FOREIGN KEY (`series_id`) REFERENCES `category` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS `latex_template` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `latex_source` longtext NOT NULL,
  `pdf_path` varchar(512) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS `seed_migration` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `executed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_seed_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS `typst_templates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `typst_content` mediumtext DEFAULT NULL,
  `is_global` tinyint(1) DEFAULT 0,
  `series_id` int DEFAULT NULL,
  `last_pdf_path` varchar(255) DEFAULT NULL,
  `last_pdf_generated_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS `typst_variables` (
  `id` int NOT NULL AUTO_INCREMENT,
  `field_key` varchar(255) NOT NULL,
  `field_type` varchar(50) NOT NULL DEFAULT 'text',
  `field_value` text DEFAULT NULL,
  `is_global` tinyint(1) DEFAULT 0,
  `series_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS `typst_series_preferences` (
  `series_id` int NOT NULL,
  `last_global_template_id` int DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`series_id`),
  KEY `fk_series_pref_template` (`last_global_template_id`),
  CONSTRAINT `fk_series_pref_template` FOREIGN KEY (`last_global_template_id`) REFERENCES `typst_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS `latex_templates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `latex_code` mediumtext NOT NULL,
  `is_global` tinyint(1) NOT NULL DEFAULT 0,
  `series_id` int DEFAULT NULL,
  `last_pdf_path` varchar(512) DEFAULT NULL,
  `last_pdf_generated_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS `latex_variables` (
  `id` int NOT NULL AUTO_INCREMENT,
  `field_key` varchar(255) NOT NULL,
  `field_type` varchar(50) NOT NULL DEFAULT 'text',
  `field_value` text DEFAULT NULL,
  `is_global` tinyint(1) NOT NULL DEFAULT 0,
  `series_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ];

    /**
     * Required non-ID columns that may be missing from legacy clones.
     * Definitions use safe defaults so adding a column never rewrites existing values.
     *
     * @var array<string, array<string, string>>
     */
    private const ADDITIVE_COLUMNS = [
        'category' => [
            'typst_templating_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'latex_templating_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ],
        'series_custom_field' => [
            'is_public_portal_hidden' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'is_backend_portal_hidden' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'is_public_hidden' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'is_backend_hidden' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ],
        'typst_templates' => [
            'title' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'description' => 'TEXT NULL',
            'typst_content' => 'MEDIUMTEXT NULL',
            'is_global' => 'TINYINT(1) NULL DEFAULT 0',
            'series_id' => 'INT NULL DEFAULT NULL',
            'last_pdf_path' => 'VARCHAR(255) NULL DEFAULT NULL',
            'last_pdf_generated_at' => 'DATETIME NULL DEFAULT NULL',
            'created_at' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ],
        'typst_variables' => [
            'field_key' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'field_type' => "VARCHAR(50) NOT NULL DEFAULT 'text'",
            'field_value' => 'TEXT NULL',
            'is_global' => 'TINYINT(1) NULL DEFAULT 0',
            'series_id' => 'INT NULL DEFAULT NULL',
            'created_at' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ],
        'typst_series_preferences' => [
            'last_global_template_id' => 'INT NULL DEFAULT NULL',
            'updated_at' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ],
        'latex_templates' => [
            'title' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'description' => 'TEXT NULL',
            'latex_code' => 'MEDIUMTEXT NULL',
            'is_global' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'series_id' => 'INT NULL DEFAULT NULL',
            'last_pdf_path' => 'VARCHAR(512) NULL DEFAULT NULL',
            'last_pdf_generated_at' => 'DATETIME NULL DEFAULT NULL',
            'created_at' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ],
        'latex_variables' => [
            'field_key' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'field_type' => "VARCHAR(50) NOT NULL DEFAULT 'text'",
            'field_value' => 'TEXT NULL',
            'is_global' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'series_id' => 'INT NULL DEFAULT NULL',
            'created_at' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connectionName = (string) config('catalog.connection', 'catalog');
        $connection = DB::connection($connectionName);
        if ($connection->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::CREATE_TABLES as $createStatement) {
            $connection->statement($createStatement);
        }

        $schema = Schema::connection($connectionName);
        $this->addMissingColumns($connection, $schema);
        $this->validateRequiredTables($schema);
        $connection->update(
            'UPDATE category SET typst_templating_enabled = latex_templating_enabled
             WHERE typst_templating_enabled IS NULL OR typst_templating_enabled = 0'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Catalog tables contain imported business data; rollback never drops them.
    }

    private function addMissingColumns(Connection $connection, Builder $schema): void
    {
        foreach (self::ADDITIVE_COLUMNS as $table => $columns) {
            foreach ($columns as $column => $definition) {
                if (! $schema->hasColumn($table, $column)) {
                    $connection->statement(sprintf(
                        'ALTER TABLE `%s` ADD COLUMN `%s` %s',
                        $table,
                        $column,
                        $definition
                    ));
                }
            }
        }
    }

    private function validateRequiredTables(Builder $schema): void
    {
        foreach (array_keys(self::EXPECTED_COLUMNS) as $table) {
            if (! $schema->hasTable($table)) {
                throw new RuntimeException(sprintf('Catalog migration did not create required table "%s".', $table));
            }
            foreach (self::EXPECTED_COLUMNS[$table] as $column) {
                if (! $schema->hasColumn($table, $column)) {
                    throw new RuntimeException(sprintf(
                        'Catalog table "%s" is missing required column "%s".',
                        $table,
                        $column
                    ));
                }
            }
        }
    }

    /**
     * Columns used by the Catalog repositories and compilation services.
     *
     * @var array<string, list<string>>
     */
    private const EXPECTED_COLUMNS = [
        'category' => ['id', 'parent_id', 'name', 'type', 'typst_templating_enabled', 'latex_templating_enabled', 'display_order', 'created_at', 'updated_at'],
        'product' => ['id', 'series_id', 'sku', 'name', 'description'],
        'series_custom_field' => ['id', 'series_id', 'field_key', 'label', 'field_type', 'field_scope', 'default_value', 'sort_order', 'is_required', 'is_public_portal_hidden', 'is_backend_portal_hidden', 'is_public_hidden', 'is_backend_hidden', 'created_at', 'updated_at'],
        'product_custom_field_value' => ['id', 'product_id', 'series_custom_field_id', 'value', 'created_at', 'updated_at'],
        'series_custom_field_value' => ['id', 'series_id', 'series_custom_field_id', 'value', 'created_at', 'updated_at'],
        'latex_template' => ['id', 'title', 'description', 'latex_source', 'pdf_path', 'created_at', 'updated_at'],
        'seed_migration' => ['id', 'name', 'executed_at'],
        'typst_templates' => ['id', 'title', 'description', 'typst_content', 'is_global', 'series_id', 'last_pdf_path', 'last_pdf_generated_at', 'created_at', 'updated_at'],
        'typst_variables' => ['id', 'field_key', 'field_type', 'field_value', 'is_global', 'series_id', 'created_at', 'updated_at'],
        'typst_series_preferences' => ['series_id', 'last_global_template_id', 'updated_at'],
        'latex_templates' => ['id', 'title', 'description', 'latex_code', 'is_global', 'series_id', 'last_pdf_path', 'last_pdf_generated_at', 'created_at', 'updated_at'],
        'latex_variables' => ['id', 'field_key', 'field_type', 'field_value', 'is_global', 'series_id', 'created_at', 'updated_at'],
    ];
};
