<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $series_id
 * @property string $sku
 * @property string $name
 * @property string|null $description
 * @property-read CatalogNode $series
 */
class CatalogProduct extends CatalogModel
{
    protected $table = 'product';

    public function series(): BelongsTo
    {
        return $this->belongsTo(CatalogNode::class, 'series_id');
    }
}
