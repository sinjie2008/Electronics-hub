<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB as Database;

final class Db
{
    public static function connection(): Connection
    {
        return Database::connection((string) config('catalog.connection', 'catalog'));
    }
}
