<?php

namespace Tests\Feature;

use App\Ai\Agents\LaravelGuideAgent;
use App\Ai\Tools\ProjectInfoTool;
use App\Mcp\Servers\SystemServer;
use App\Mcp\Tools\SystemInfoTool;
use Laravel\Ai\Tools\Request as AiToolRequest;
use Laravel\Mcp\Server\Registrar;
use Modules\Core\Support\ApplicationInfo;
use Tests\TestCase;

final class AiMcpTest extends TestCase
{
    public function test_ai_agent_uses_a_fake_response_without_provider_credentials(): void
    {
        LaravelGuideAgent::fake(['A safe test answer.'])->preventStrayPrompts();

        $response = LaravelGuideAgent::make()->prompt('What does this project use?');

        $this->assertSame('A safe test answer.', $response->text);
        LaravelGuideAgent::assertPrompted('What does this project use?');

        $tools = [...LaravelGuideAgent::make()->tools()];

        $this->assertCount(1, $tools);
        $this->assertInstanceOf(ProjectInfoTool::class, $tools[0]);
    }

    public function test_ai_project_info_tool_returns_only_application_name_and_framework_version(): void
    {
        $response = (string) (new ProjectInfoTool)->handle(new AiToolRequest);

        $this->assertSame(
            app(ApplicationInfo::class)->toArray(),
            json_decode($response, true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_mcp_registers_one_local_system_server_and_no_web_server(): void
    {
        $registrar = app(Registrar::class);

        $this->assertIsCallable($registrar->getLocalServer('system'));
        $this->assertNull($registrar->getWebServer('mcp/system'));

        SystemServer::tools()->assertRegistered(SystemInfoTool::class);
    }

    public function test_mcp_system_info_returns_exactly_the_two_safe_fields(): void
    {
        SystemServer::tool(SystemInfoTool::class)
            ->assertOk()
            ->assertStructuredContent(app(ApplicationInfo::class)->toArray());
    }
}
