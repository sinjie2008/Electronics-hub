<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $series_id
 * @property string $field_key
 * @property string $label
 * @property string $field_type
 * @property string $field_scope
 * @property string|null $default_value
 * @property int $sort_order
 * @property bool $is_required
 * @property bool $is_public_portal_hidden
 * @property bool $is_backend_portal_hidden
 * @property-read CatalogNode $series
 */
class SeriesField extends CatalogModel
{
    protected $table = 'series_custom_field';

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_public_portal_hidden' => 'boolean',
            'is_backend_portal_hidden' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(CatalogNode::class, 'series_id');
    }
}
