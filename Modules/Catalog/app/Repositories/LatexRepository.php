<?php

declare(strict_types=1);

namespace Modules\Catalog\Repositories;

use Illuminate\Database\Connection;

/**
 * Stores LaTeX templates and variables and reads series data for compilation.
 */
final class LatexRepository
{
    private Connection $db;

    /**
     * Create the repository with the application's database connection.
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * List global LaTeX templates.
     *
     * @return list<array<string, mixed>>
     */
    public function listGlobalTemplates(): array
    {
        return $this->fetchAll(
            'SELECT id, title, description, latex_code, is_global, series_id, last_pdf_path, last_pdf_generated_at, created_at, updated_at
             FROM latex_templates WHERE is_global = 1 AND (series_id IS NULL OR series_id = 0)
             ORDER BY updated_at DESC, id DESC'
        );
    }

    /**
     * Fetch a global template by id.
     *
     * @return array<string, mixed>|null
     */
    public function findGlobalTemplate(int $id): ?array
    {
        return $this->findOne(
            'SELECT id, title, description, latex_code, is_global, series_id, last_pdf_path, last_pdf_generated_at, created_at, updated_at
             FROM latex_templates WHERE id = ? AND is_global = 1 LIMIT 1',
            [$id]
        );
    }

    /**
     * Insert a global template and return its database id.
     */
    public function insertGlobalTemplate(string $title, string $description, string $latexCode): int
    {
        return $this->insertId(
            'INSERT INTO latex_templates (title, description, latex_code, is_global, series_id) VALUES (?, ?, ?, 1, NULL)',
            [$title, $description, $latexCode]
        );
    }

    /**
     * Update a global template.
     */
    public function updateGlobalTemplate(int $id, string $title, string $description, string $latexCode): void
    {
        $this->db->update(
            'UPDATE latex_templates SET title = ?, description = ?, latex_code = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND is_global = 1',
            [$title, $description, $latexCode, $id]
        );
    }

    /**
     * Delete a global template.
     */
    public function deleteGlobalTemplate(int $id): bool
    {
        return $this->execute('DELETE FROM latex_templates WHERE id = ? AND is_global = 1 LIMIT 1', [$id]);
    }

    /**
     * List templates owned by a series together with global templates.
     *
     * @return list<array<string, mixed>>
     */
    public function listSeriesTemplates(int $seriesId): array
    {
        return $this->fetchAll(
            'SELECT id, title, description, latex_code, is_global, series_id, last_pdf_path, last_pdf_generated_at, created_at, updated_at
             FROM latex_templates WHERE series_id = ? OR (is_global = 1 AND (series_id IS NULL OR series_id = 0))
             ORDER BY is_global DESC, updated_at DESC',
            [$seriesId]
        );
    }

    /**
     * Fetch a template by id.
     *
     * @return array<string, mixed>|null
     */
    public function findTemplate(int $id): ?array
    {
        return $this->findOne(
            'SELECT id, title, description, latex_code, is_global, series_id, last_pdf_path, last_pdf_generated_at, created_at, updated_at
             FROM latex_templates WHERE id = ? LIMIT 1',
            [$id]
        );
    }

    /**
     * Insert a series template and return its database id.
     */
    public function insertSeriesTemplate(int $seriesId, string $title, string $description, string $latexCode): int
    {
        return $this->insertId(
            'INSERT INTO latex_templates (title, description, latex_code, is_global, series_id) VALUES (?, ?, ?, 0, ?)',
            [$title, $description, $latexCode, $seriesId]
        );
    }

    /**
     * Update a series-owned template.
     */
    public function updateSeriesTemplate(int $id, int $seriesId, string $title, string $description, string $latexCode): void
    {
        $this->db->update(
            'UPDATE latex_templates SET title = ?, description = ?, latex_code = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND series_id = ?',
            [$title, $description, $latexCode, $id, $seriesId]
        );
    }

    /**
     * Retrieve products for a series in SKU order.
     *
     * @return list<array<string, mixed>>
     */
    public function getSeriesProducts(int $seriesId): array
    {
        return $this->fetchAll('SELECT id, name, sku FROM product WHERE series_id = ? ORDER BY sku ASC', [$seriesId]);
    }

    /**
     * Retrieve stored attribute values for a product.
     *
     * @return list<array<string, mixed>>
     */
    public function getProductAttributes(int $productId): array
    {
        return $this->fetchAll(
            'SELECT f.field_key, v.value FROM product_custom_field_value v
             JOIN series_custom_field f ON v.series_custom_field_id = f.id WHERE v.product_id = ?',
            [$productId]
        );
    }

    /**
     * List global LaTeX variables.
     *
     * @return list<array<string, mixed>>
     */
    public function listGlobalVariables(): array
    {
        return $this->fetchAll(
            'SELECT id, field_key, field_type, field_value, is_global, series_id, created_at, updated_at
             FROM latex_variables WHERE is_global = 1 AND (series_id IS NULL OR series_id = 0) ORDER BY field_key ASC'
        );
    }

    /**
     * Update a global LaTeX variable.
     */
    public function updateGlobalVariable(int $id, string $key, string $type, string $value): void
    {
        $this->db->update(
            'UPDATE latex_variables SET field_key = ?, field_type = ?, field_value = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND is_global = 1',
            [$key, $type, $value, $id]
        );
    }

    /**
     * Insert a global LaTeX variable and return its database id.
     */
    public function insertGlobalVariable(string $key, string $type, string $value): int
    {
        return $this->insertId(
            'INSERT INTO latex_variables (field_key, field_type, field_value, is_global, series_id) VALUES (?, ?, ?, 1, NULL)',
            [$key, $type, $value]
        );
    }

    /**
     * Delete a global LaTeX variable.
     */
    public function deleteGlobalVariable(int $id): bool
    {
        return $this->execute('DELETE FROM latex_variables WHERE id = ? AND is_global = 1 LIMIT 1', [$id]);
    }

    /**
     * Fetch a global LaTeX variable by id.
     *
     * @return array<string, mixed>|null
     */
    public function findGlobalVariable(int $id): ?array
    {
        return $this->findOne(
            'SELECT id, field_key, field_type, field_value, is_global, series_id, created_at, updated_at
             FROM latex_variables WHERE id = ? AND is_global = 1 LIMIT 1',
            [$id]
        );
    }

    /**
     * Fetch a list of rows with bound values.
     *
     * @param  list<mixed>  $params
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, array $params = []): array
    {
        return array_map(
            static fn (object $row): array => (array) $row,
            $this->db->select($sql, $params)
        );
    }

    /**
     * Fetch one row with bound values.
     *
     * @param  list<mixed>  $params
     * @return array<string, mixed>|null
     */
    private function findOne(string $sql, array $params): ?array
    {
        $row = $this->db->selectOne($sql, $params);

        return $row === null ? null : (array) $row;
    }

    /**
     * Execute a bound-value mutation and return its result.
     *
     * @param  list<mixed>  $params
     */
    private function execute(string $sql, array $params): bool
    {
        return $this->db->statement($sql, $params);
    }

    /**
     * Executes a bound insert and returns the generated row ID.
     *
     * @param  list<mixed>  $bindings
     */
    private function insertId(string $sql, array $bindings): int
    {
        if (! $this->db->insert($sql, $bindings)) {
            throw new \RuntimeException('Failed to execute insert query.');
        }

        return (int) $this->db->getPdo()->lastInsertId();
    }
}
