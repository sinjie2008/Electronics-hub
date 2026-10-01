<?php

declare(strict_types=1);

namespace Modules\Catalog\Repositories;

use mysqli;

/**
 * Handles Typst template, preference, variable, and compilation-data persistence.
 */
final class TypstRepository
{
    private mysqli $db;

    /**
     * Use the schema installed by the module migration.
     */
    public function __construct(mysqli $db)
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
            'i',
            [$id]
        );
    }

    /**
     * Insert a global template and return its id.
     */
    public function insertGlobalTemplate(string $title, string $description, string $code, ?string $pdfPath): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO typst_templates (title, description, typst_content, is_global, series_id, last_pdf_path, last_pdf_generated_at)
             VALUES (?, ?, ?, 1, NULL, ?, ?)'
        );
        $generatedAt = $pdfPath ? date('Y-m-d H:i:s') : null;
        $stmt->bind_param('sssss', $title, $description, $code, $pdfPath, $generatedAt);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();

        return $id;
    }

    /**
     * Update a global template.
     */
    public function updateGlobalTemplate(int $id, string $title, string $description, string $code, ?string $pdfPath): void
    {
        $sql = 'UPDATE typst_templates SET title = ?, description = ?, typst_content = ?, updated_at = CURRENT_TIMESTAMP';
        $params = [$title, $description, $code];
        $types = 'sss';
        if ($pdfPath !== null) {
            $sql .= ', last_pdf_path = ?, last_pdf_generated_at = ?';
            $params[] = $pdfPath;
            $params[] = date('Y-m-d H:i:s');
            $types .= 'ss';
        }
        $sql .= ' WHERE id = ? AND is_global = 1';
        $params[] = $id;
        $types .= 'i';

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Delete a global template.
     */
    public function deleteGlobalTemplate(int $id): bool
    {
        return $this->execute('DELETE FROM typst_templates WHERE id = ? AND is_global = 1 LIMIT 1', 'i', [$id]);
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
            'i',
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
            'i',
            [$id]
        );
    }

    /**
     * Insert a series template and return its id.
     */
    public function insertSeriesTemplate(int $seriesId, string $title, string $description, string $code, ?string $pdfPath): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO typst_templates (title, description, typst_content, is_global, series_id, last_pdf_path, last_pdf_generated_at)
             VALUES (?, ?, ?, 0, ?, ?, ?)'
        );
        $generatedAt = $pdfPath ? date('Y-m-d H:i:s') : null;
        $stmt->bind_param('sssiss', $title, $description, $code, $seriesId, $pdfPath, $generatedAt);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();

        return $id;
    }

    /**
     * Update a series template.
     */
    public function updateSeriesTemplate(int $id, int $seriesId, string $title, string $description, string $code, ?string $pdfPath): void
    {
        $sql = 'UPDATE typst_templates SET title = ?, description = ?, typst_content = ?, updated_at = CURRENT_TIMESTAMP';
        $params = [$title, $description, $code];
        $types = 'sss';
        if ($pdfPath !== null) {
            $sql .= ', last_pdf_path = ?, last_pdf_generated_at = ?';
            $params[] = $pdfPath;
            $params[] = date('Y-m-d H:i:s');
            $types .= 'ss';
        }
        $sql .= ' WHERE id = ? AND series_id = ?';
        $params[] = $id;
        $params[] = $seriesId;
        $types .= 'ii';

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Delete a Typst template by id.
     */
    public function deleteTemplate(int $id): bool
    {
        return $this->execute('DELETE FROM typst_templates WHERE id = ? LIMIT 1', 'i', [$id]);
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
            'i',
            [$seriesId]
        );
    }

    /**
     * Save the selected global template id for one series.
     */
    public function saveSeriesPreference(int $seriesId, ?int $templateId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO typst_series_preferences (series_id, last_global_template_id, updated_at)
             VALUES (?, ?, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE last_global_template_id = VALUES(last_global_template_id), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->bind_param('ii', $seriesId, $templateId);
        $stmt->execute();
        $stmt->close();
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
            'i',
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
            $types = 'sssii';
            $params = [$key, $type, $value, $id, $isGlobal ? 1 : 0];
            if (! $isGlobal) {
                $sql .= ' AND series_id = ?';
                $types .= 'i';
                $params[] = $seriesId ?? 0;
            }
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();

            return $this->findVariable($id, $isGlobal, $seriesId);
        }

        $isGlobalFlag = $isGlobal ? 1 : 0;
        $seriesValue = $isGlobal ? 0 : ($seriesId ?? 0);
        $stmt = $this->db->prepare(
            'INSERT INTO typst_variables (field_key, field_type, field_value, is_global, series_id) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('sssii', $key, $type, $value, $isGlobalFlag, $seriesValue);
        $stmt->execute();
        $newId = (int) $this->db->insert_id;
        $stmt->close();

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
        $types = 'ii';
        $params = [$id, $isGlobal ? 1 : 0];
        if (! $isGlobal) {
            $sql .= ' AND series_id = ?';
            $types .= 'i';
            $params[] = $seriesId ?? 0;
        }
        $sql .= ' LIMIT 1';

        return $this->findOne($sql, $types, $params);
    }

    /**
     * Delete a global variable.
     */
    public function deleteGlobalVariable(int $id): bool
    {
        return $this->execute('DELETE FROM typst_variables WHERE id = ? AND is_global = 1 LIMIT 1', 'i', [$id]);
    }

    /**
     * Delete a variable belonging to a series.
     */
    public function deleteScopedVariable(int $id, int $seriesId): bool
    {
        return $this->execute(
            'DELETE FROM typst_variables WHERE id = ? AND is_global = 0 AND series_id = ? LIMIT 1',
            'ii',
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
        return $this->fetchAll('SELECT id, name, sku FROM product WHERE series_id = ? ORDER BY sku ASC', 'i', [$seriesId]);
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
            'i',
            [$productId]
        );
    }

    /**
     * Fetch all rows for an operation on Typst-owned records.
     *
     * @param  list<mixed>  $params
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if ($params !== []) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * Fetch one row for a Typst-owned record.
     *
     * @param  list<mixed>  $params
     * @return array<string, mixed>|null
     */
    private function findOne(string $sql, string $types, array $params): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }

    /**
     * Execute a Typst-owned mutation with bound values.
     *
     * @param  list<mixed>  $params
     */
    private function execute(string $sql, string $types, array $params): bool
    {
        $stmt = $this->db->prepare($sql);
        $result = $stmt->bind_param($types, ...$params) && $stmt->execute();
        $stmt->close();

        return $result;
    }
}
