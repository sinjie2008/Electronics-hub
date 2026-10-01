<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\ProjectInfoTool;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;

final class LaravelGuideAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Help with questions about this Laravel application. Use the read-only project info tool when the user asks for the application name or Laravel version. Do not infer or disclose configuration values that the tool does not return.';
    }

    /**
     * @return list<Tool>
     */
    public function tools(): iterable
    {
        return [new ProjectInfoTool];
    }
}
