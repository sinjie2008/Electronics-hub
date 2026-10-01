<?php

declare(strict_types=1);

namespace Modules\System\Settings;

use Spatie\LaravelSettings\Settings;

final class SystemSettings extends Settings
{
    public string $application_name;

    public string $application_description;

    public string $timezone;

    public string $default_locale;

    public int $pagination_size;

    public string $default_ai_provider;

    public string $default_ai_model;

    public static function group(): string
    {
        return 'system';
    }
}
