<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property string|null $typst_content
 * @property bool $is_global
 * @property int|null $series_id
 * @property string|null $last_pdf_path
 * @property Carbon|null $last_pdf_generated_at
 * @property-read CatalogNode|null $series
 */
class TypstTemplate extends CatalogModel
{
    protected $table = 'typst_templates';

    protected function casts(): array
    {
        return ['is_global' => 'boolean', 'last_pdf_generated_at' => 'datetime'];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(CatalogNode::class, 'series_id');
    }
}
