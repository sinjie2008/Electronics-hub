<?php

namespace Modules\IAM\Services\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

trait LogsIamActivities
{
    /**
     * @param  array<string, mixed>  $properties
     */
    protected function logIamActivity(User $actor, string $event, Model $subject, array $properties = []): void
    {
        activity('administration')
            ->causedBy($actor)
            ->performedOn($subject)
            ->event($event)
            ->withProperties($properties)
            ->log($event);
    }
}
