<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Modules\Core\Support\ApplicationInfo;

final class ProjectInfoTool implements Tool
{
    public function description(): string
    {
        return 'Read the application name and Laravel version. This tool does not read secrets or modify application state.';
    }

    public function handle(Request $request): string
    {
        return json_encode(
            app(ApplicationInfo::class)->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
