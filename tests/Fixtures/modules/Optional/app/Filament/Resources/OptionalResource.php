<?php

namespace Modules\Optional\Filament\Resources;

use App\Models\User;
use Filament\Resources\Resource;

class OptionalResource extends Resource
{
    protected static ?string $model = User::class;
}
