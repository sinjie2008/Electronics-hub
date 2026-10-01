<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/** Read projections for Filament. Catalog services own all writes and transactions. */
abstract class CatalogModel extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    public function getConnectionName(): ?string
    {
        return (string) config('catalog.connection', 'catalog');
    }
}
