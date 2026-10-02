<?php

declare(strict_types=1);

namespace Modules\Catalog\Repositories;

use Illuminate\Database\Connection;

/**
 * Handles Typst template, preference, variable, and compilation-data persistence.
 */
final class TypstRepository
{
    private Connection $db;

    /**
     * Create the repository with the module's configured database connection.
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * List global Typst templates.
     *
     * @return list<array<string, mixed>>
     */
    public function listGlobalTemplates(): array
    {
        return $this->fetchAll(
            'SELECT id, title, description, typst_content, is_global, series_id, last_pdf_path, last_pdf_generated_at, created_at, updated_at
             FROM typst_templates WHERE is_global = 1 AND (series_id IS NULL OR series_id = 0)
             ORDER BY updated_at DESC, id DESC'
        );
    }

    /**
     * Find a global Typst template by id.
     *
     * @return array<string, mixed>|null
     */
    public function findGlobalTemplate(int $id): ?array
    {
        return $this->findOne(
            'SELECT id, title, description, typst_content, is_global, series_id, last_pdf_path, last_pdf_generated_at, created_at, updated_at
             FROM typst_templates WHERE id = ? AND is_global = 1 LIMIT 1',
            [$id]
        );
    }

    /**
     * Insert a global template and return its id.
     */
    public function insertGlobalTemplate(string $title, string $description, string $code, ?string $pdfPath): int
    {
        $generatedAt = $pdfPath ? date('Y-m-d H:i:s') : null;

        return $this->insertId(
            'INSERT INTO typst_templates (title, description, typst_content, is_global, series_id, last_pdf_path, last_pdf_generated_at)
             VALUES (?, ?, ?, 1, NULL, ?, ?)',
            [$title, $description, $code, $pdfPath, $generatedAt]
        );
    }

    /**
     * Update a global template.
     */
    public function updateGlobalTemplate(int $id, string $title, string $description, string $code, ?string $pdfPath): void
    {
        $sql = 'UPDATE typst_templates SET title = ?, description = ?, typst_content = ?, updated_at = CURRENT_TIMESTAMP';
        $params = [$title, $description, $code];
        if ($pdfPath !== null) {
            $sql .= ', last_pdf_path = ?, last_pdf_generated_at = ?';
            $params[] = $pdfPath;
            $params[] = date('Y-m-d H:i:s');
        }
        $sql .= ' WHERE id = ? AND is_global = 1';
        $params[] = $id;
        $this->db->update($sql, $params);
    }

    /**
     * Delete a global template.
     */
    public function deleteGlobalTemplate(int $id): bool
    {
        return $this->execute('DELETE FROM typst_templates WHERE id = ? AND is_global = 1 LIMIT 1', [$id]);
    }

    /**
     * List templates visible to a series.
     *
     * @return list<array<string, mixed>>
     */
    public function listSeriesTemplates(int $seriesId): array
    {
        return $this->fetchAll(
            'SELECT id, title, description, typst_content, is_global, series_id, last_pdf_path, last_pdf_generated_at, created_at, updated_at
             FROM typst_templates WHERE series_id = ? OR (is_global = 1 AND (series_id IS NULL OR series_id = 0))
             ORDER BY is_global DESC, updated_at DESC',
            [$seriesId]
        );
    }

    /**
     * Find a Typst template by id.
     *
     * @return array<string, mixed>|null
     */
    public function findTemplate(int $id): ?array
    {
        return $this->findOne(
            'SELECT id, title, description, typst_content, is_global, series_id, last_pdf_path, last_pdf_generated_at, created_at, updated_at
             FROM typst_templates WHERE id = ? LIMIT 1',
            [$id]
        );
    }

    /**
     * Insert a series template and return its id.
     */
    public function insertSeriesTemplate(int $seriesId, string $title, string $description, string $code, ?string $pdfPath): int
    {
        $generatedAt = $pdfPath ? date('Y-m-d H:i:s') : null;

        return $this->insertId(
            'INSERT INTO typst_templates (title, description, typst_content, is_global, series_id, last_pdf_path, last_pdf_generated_at)
             VALUES (?, ?, ?, 0, ?, ?, ?)',
            [$title, $description, $code, $seriesId, $pdfPath, $generatedAt]
        );
    }

    /**
     * Update a series template.
     */
    public function updateSeriesTemplate(int $id, int $seriesId, string $title, string $description, string $code, ?string $pdfPath): void
    {
        $sql = 'UPDATE typst_templates SET title = ?, description = ?, typst_content = ?, updated_at = CURRENT_TIMESTAMP';
        $params = [$title, $description, $code];
        if ($pdfPath !== null) {
            $sql .= ', last_pdf_path = ?, last_pdf_generated_at = ?';
            $params[] = $pdfPath;
            $params[] = date('Y-m-d H:i:s');
        }
        $sql .= ' WHERE id = ? AND series_id = ?';
        $params[] = $id;
        $params[] = $seriesId;
        $this->db->update($sql, $params);
    }

    /**
     * Delete a Typst template by id.
     */
    public function deleteTemplate(int $id): bool
    {
        return $this->execute('DELETE FROM typst_templates WHERE id = ? LIMIT 1', [$id]);
    }

    /**
     * Read the selected global template id for one series.
     *
     * @return array<string, mixed>|null
     */
    public function findSeriesPreference(int $seriesId): ?array
    {
        return $this->findOne(
            'SELECT last_global_template_id FROM typst_series_preferences WHERE series_id = ? LIMIT 1',
            [$seriesId]
        );
    }

    /**
     * Save the selected global template id for one series.
     */
    public function saveSeriesPreference(int $seriesId, ?int $templateId): void
    {
        $this->db->statement(
            'INSERT INTO typst_series_preferences (series_id, last_global_template_id, updated_at)
             VALUES (?, ?, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE last_global_template_id = VALUES(last_global_template_id), updated_at = CURRENT_TIMESTAMP',
            [$seriesId, $templateId]
        );
    }

    /**
     * List global Typst variables.
     *
     * @return list<array<string, mixed>>
     */
    public function listGlobalVariables(): array
    {
        return $this->fetchAll(
            'SELECT id, field_key, field_type, field_value, is_global, series_id, created_at, updated_at
             FROM typst_variables WHERE is_global = 1 AND (series_id IS NULL OR series_id = 0) ORDER BY field_key ASC'
        );
    }

    /**
     * List variables owned by a series.
     *
     * @return list<array<string, mixed>>
     */
    public function listScopedVariables(int $seriesId): array
    {
        return $this->fetchAll(
            'SELECT id, field_key, field_type, field_value, is_global, series_id, created_at, updated_at
             FROM typst_variables WHERE is_global = 0 AND series_id = ? ORDER BY field_key ASC',
            [$seriesId]
        );
    }

    /**
     * Persist a global or series-scoped variable and return its stored row.
     *
     * @return array<string, mixed>|null
     */
    public function saveVariable(string $key, string $type, string $value, ?int $id, bool $isGlobal, ?int $seriesId): ?array
    {
        if ($id) {
            $sql = 'UPDATE typst_variables SET field_key = ?, field_type = ?, field_value = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND is_global = ?';
            $params = [$key, $type, $value, $id, $isGlobal ? 1 : 0];
            if (! $isGlobal) {
                $sql .= ' AND series_id = ?';
                $params[] = $seriesId ?? 0;
            }
            $this->db->update($sql, $params);

            return $this->findVariable($id, $isGlobal, $seriesId);
        }

        $isGlobalFlag = $isGlobal ? 1 : 0;
        $seriesValue = $isGlobal ? 0 : ($seriesId ?? 0);
        $newId = $this->insertId(
            'INSERT INTO typst_variables (field_key, field_type, field_value, is_global, series_id) VALUES (?, ?, ?, ?, ?)',
            [$key, $type, $value, $isGlobalFlag, $seriesValue]
        );

        return $this->findVariable($newId, $isGlobal, $seriesId);
    }

    /**
     * Find a variable by id and ownership scope.
     *
     * @return array<string, mixed>|null
     */
    public function findVariable(int $id, bool $isGlobal, ?int $seriesId = null): ?array
    {
        $sql = 'SELECT id, field_key, field_type, field_value, is_global, series_id, created_at, updated_at
                FROM typst_variables WHERE id = ? AND is_global = ?';
        $params = [$id, $isGlobal ? 1 : 0];
        if (! $isGlobal) {
            $sql .= ' AND series_id = ?';
            $params[] = $seriesId ?? 0;
        }
        $sql .= ' LIMIT 1';

        return $this->findOne($sql, $params);
    }

    /**
     * Delete a global variable.
     */
    public function deleteGlobalVariable(int $id): bool
    {
        return $this->execute('DELETE FROM typst_variables WHERE id = ? AND is_global = 1 LIMIT 1', [$id]);
    }

    /**
     * Delete a variable belonging to a series.
     */
    public function deleteScopedVariable(int $id, int $seriesId): bool
    {
        return $this->execute(
            'DELETE FROM typst_variables WHERE id = ? AND is_global = 0 AND series_id = ? LIMIT 1',
            [$id, $seriesId]
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
     * Retrieve custom field values for a product.
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
     * Fetch all rows for an operation on Typst-owned records.
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
     * Fetch one row for a Typst-owned record.
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
     * Execute a Typst-owned mutation with bound values.
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
