# Laravel AI SDK

The example `LaravelGuideAgent` answers general questions about the application and has one read-only tool, `ProjectInfoTool`. The tool returns only the configured application name and Laravel framework version; it does not inspect credentials, environment variables, or database contents.

Install and publish the SDK with:

```sh
composer require laravel/ai
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"
php artisan migrate
```

The publish command adds `config/ai.php` and the conversation tables. Set a provider API key in the environment only when making a real provider request. The example test fakes the agent response and prevents unmatched prompts from reaching a provider, so it needs no API key.

Example use:

```php
use App\Ai\Agents\LaravelGuideAgent;

$response = LaravelGuideAgent::make()->prompt('What is the Laravel version?');
```

Tests live in `tests/Feature/AiMcpTest.php` and cover the fake agent response and the tool's bounded output.
