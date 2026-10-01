# Local MCP server

The `system` server uses Laravel MCP's local STDIO transport. Its only tool, `SystemInfoTool`, returns a structured result containing exactly the configured application name and Laravel version. It has no input arguments, marks itself read-only, idempotent, non-destructive, and closed-world, and performs no writes or external calls.

Laravel MCP loads `routes/ai.php` when the application boots. The file registers the server with `Mcp::local('system', SystemServer::class)`. Start it from the application root with:

```sh
php artisan mcp:start system
```

This is a local process integration. A client that launches it runs with the operating-system permissions and application environment of that process. Keep it configured only for trusted local clients. Before exposing any MCP server over HTTP, add authentication and authorization, review each tool's data access, and test its visibility for each user role.

`tests/Feature/AiMcpTest.php` verifies local registration, the advertised tool, and the exact structured output. Laravel MCP's server testing helpers execute the tool directly without launching a provider or external service.
