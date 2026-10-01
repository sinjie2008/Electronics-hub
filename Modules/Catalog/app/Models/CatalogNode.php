<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string $type
 * @property bool $typst_templating_enabled
 * @property int $display_order
 * @property-read self|null $parent
 */
class CatalogNode extends CatalogModel
{
    protected $table = 'category';

    protected function casts(): array
    {
        return ['typst_templating_enabled' => 'boolean', 'display_order' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(CatalogProduct::class, 'series_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(SeriesField::class, 'series_id');
    }
}
