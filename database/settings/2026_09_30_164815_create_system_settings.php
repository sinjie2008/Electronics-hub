<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('system.application_name', config('app.name', 'Laravel Enterprise'));
        $this->migrator->add('system.application_description', 'Modular Laravel application');
        $this->migrator->add('system.timezone', config('app.timezone', 'UTC'));
        $this->migrator->add('system.default_locale', config('app.locale', 'en'));
        $this->migrator->add('system.pagination_size', 15);
        $this->migrator->add('system.default_ai_provider', 'openai');
        $this->migrator->add('system.default_ai_model', '');
    }

    public function down(): void
    {
        foreach (['application_name', 'application_description', 'timezone', 'default_locale', 'pagination_size', 'default_ai_provider', 'default_ai_model'] as $name) {
            $this->migrator->delete('system.'.$name);
        }
    }
};
