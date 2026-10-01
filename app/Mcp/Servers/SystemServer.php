<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\SystemInfoTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('System Information')]
#[Version('1.0.0')]
#[Instructions('Expose only the application name and Laravel version through a local process.')]
final class SystemServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        SystemInfoTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
