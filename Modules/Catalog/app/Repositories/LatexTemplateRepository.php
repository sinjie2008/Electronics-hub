<?php

declare(strict_types=1);

namespace Modules\Catalog\Repositories;

use Illuminate\Database\Connection;

/**
 * Persists and reads LaTeX template records.
 */
final class LatexTemplateRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Returns all templates in their existing display order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchTemplates(): array
    {
        return array_map(
            static fn (object $row): array => (array) $row,
            $this->connection->select(
                'SELECT id, title, description, pdf_path, created_at, updated_at
                 FROM latex_template
                 ORDER BY updated_at DESC, id DESC'
            )
        );
    }

    /**
     * Inserts a template and returns the inserted ID.
     */
    public function insertTemplate(string $title, string $description, string $latex): int
    {
        return $this->insertId(
            'INSERT INTO latex_template (title, description, latex_source, created_at, updated_at)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
            [$title, $description, $latex]
        );
    }

    /**
     * Updates a template's source and description.
     */
    public function updateTemplate(int $templateId, string $title, string $description, string $latex): void
    {
        $this->connection->update(
            'UPDATE latex_template
             SET title = ?, description = ?, latex_source = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? LIMIT 1',
            [$title, $description, $latex, $templateId]
        );
    }

    /**
     * Deletes a template row.
     */
    public function deleteTemplate(int $templateId): void
    {
        $this->connection->delete('DELETE FROM latex_template WHERE id = ? LIMIT 1', [$templateId]);
    }

    /**
     * Updates the stored PDF path for a template.
     */
    public function updatePdfPath(int $templateId, string $relativePath): void
    {
        $this->connection->update(
            'UPDATE latex_template
             SET pdf_path = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? LIMIT 1',
            [$relativePath, $templateId]
        );
    }

    /**
     * Loads one template by ID.
     *
     * @return array<string, mixed>|null
     */
    public function findTemplate(int $templateId): ?array
    {
        $row = $this->connection->selectOne(
            'SELECT id, title, description, latex_source, pdf_path, created_at, updated_at
             FROM latex_template
             WHERE id = ? LIMIT 1',
            [$templateId]
        );

        return $row === null ? null : (array) $row;
    }

    /**
     * Executes a bound insert and returns the generated row ID.
     *
     * @param  list<mixed>  $bindings
     */
    private function insertId(string $sql, array $bindings): int
    {
        if (! $this->connection->insert($sql, $bindings)) {
            throw new \RuntimeException('Failed to execute insert query.');
        }

        return (int) $this->connection->getPdo()->lastInsertId();
    }
}
