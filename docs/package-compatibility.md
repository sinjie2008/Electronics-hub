# Package compatibility

This matrix describes the committed root `composer.lock`, host `package-lock.json`, and Catalog asset `Modules/Catalog/package-lock.json`. Composer constraints are taken from the locked release metadata and cross-checked against the corresponding Packagist package page; documentation links point to the upstream project documentation for the installed major version. Catalog's `composer.json` is merged into the root package through the existing merge plugin and has no separate Composer lock file. Lock files, rather than a copied version list, remain the install source of truth.

## Installed compatibility matrix

Verified on 2026-09-30 against official documentation, exact Packagist requirements, and upstream repository/release metadata before installation. Versions below are read from the committed `composer.lock`. “Verified” records compatibility evidence; integration results are recorded separately in [verification](verification.md). The effective PHP floor is **8.4.1** because of the composed stable development dependencies.

| Package | Installed Version | Laravel 13 | PHP 8.4 | Status | Notes |
| --- | ---: | --- | --- | --- | --- |
| [`laravel/framework`](https://packagist.org/packages/laravel/framework) | 13.34.0 | Yes | 8.4.1+ | Verified | Stable 13.x; application PHP floor is 8.4.1. |
| [`filament/filament`](https://packagist.org/packages/filament/filament) | 5.9.0 | Yes | 8.4.1+ | Verified | Stable 5.x with Livewire 4; native session panel. |
| [`livewire/livewire`](https://packagist.org/packages/livewire/livewire) | 4.4.7 | Yes | 8.4.1+ | Verified | Filament peer dependency; locked 4.x accepts Illuminate 13. |
| [`nwidart/laravel-modules`](https://packagist.org/packages/nwidart/laravel-modules) | 13.0.0 | Yes | 8.4.1+ | Verified | Stable 13.x; per-module Composer manifests; merge plugin permitted. |
| [`coolsam/modules`](https://packagist.org/packages/coolsam/modules) | 5.3.2 | Yes | 8.4.1+ | Verified | 5.3.2 explicitly accepts Filament 5 and Modules/Illuminate 13; authorization uses application policies. |
| [`spatie/laravel-permission`](https://packagist.org/packages/spatie/laravel-permission) | 8.3.0 | Yes | 8.4.1+ | Verified | Stable 8.x accepts Illuminate 13; ordered application Gate plus policies. |
| [`laravel/passport`](https://packagist.org/packages/laravel/passport) | 13.8.0 | Yes | 8.4.1+ | Verified | Stable 13.8; UUID clients, supported client repository, PKCE and client credentials. |
| [`laravel/scout`](https://packagist.org/packages/laravel/scout) | 11.8.0 | Yes | 8.4.1+ | Verified | Stable 11.x accepts Illuminate 13; database driver exercised. |
| [`spatie/laravel-backup`](https://packagist.org/packages/spatie/laravel-backup) | 10.3.3 | Yes | 8.4.1+ | Verified | Stable 10.x; Linux, ZIP and mysqldump. Windows server execution unsupported. |
| [`spatie/laravel-activitylog`](https://packagist.org/packages/spatie/laravel-activitylog) | 5.1.1 | Yes | 8.4.1+ | Verified | Stable 5.x requires PHP 8.4; UUID-capable audit subject schema. |
| [`spatie/laravel-settings`](https://packagist.org/packages/spatie/laravel-settings) | 3.9.0 | Yes | 8.4.1+ | Verified | Stable 3.x accepts Illuminate 13; explicit settings registration. |
| [`laravel/ai`](https://packagist.org/packages/laravel/ai) | 1.0.1 | Yes | 8.4.1+ | Verified | Stable 1.x accepts Illuminate 13; optional keys and SDK fakes. |
| [`laravel/mcp`](https://packagist.org/packages/laravel/mcp) | 1.0.1 | Yes | 8.4.1+ | Verified | Stable 1.x accepts Illuminate 13; local STDIO server. |
| [`laravel/boost`](https://packagist.org/packages/laravel/boost) | 2.10.0 | Yes | 8.4.1+ | Verified | Development only; official Codex install and package skills. |
| [`pestphp/pest`](https://packagist.org/packages/pestphp/pest) | 5.2.1 | Yes | 8.4.1+ | Verified | Stable 5.x; composed Symfony dependencies require PHP 8.4.1+. |
| [`pestphp/pest-plugin-laravel`](https://packagist.org/packages/pestphp/pest-plugin-laravel) | 5.0.1 | Yes | 8.4.1+ | Verified | Stable 5.x requires Laravel 13.23+ and Pest 5. |
| [`laravel/pint`](https://packagist.org/packages/laravel/pint) | 1.32.1 | Yes | 8.4.1+ | Verified | Development formatter; PHP 8.4 compatible. |
| [`larastan/larastan`](https://packagist.org/packages/larastan/larastan) | 3.12.2 | Yes | 8.4.1+ | Verified | Development analysis; Illuminate 13 and PHPStan 2 supported. |
| [`laravel/telescope`](https://packagist.org/packages/laravel/telescope) | 5.25.0 | Yes | 8.4.1+ | Verified | Development only; accepts Laravel 13, registered solely for local opt-in. |

The package-name links provide the independently checked Composer requirements. The following tables provide the official versioned documentation; Packagist links to each upstream source repository and release reference. Livewire 4 documentation: [installation](https://livewire.laravel.com/docs/4.x/installation); upstream: [Livewire releases](https://github.com/livewire/livewire/releases).

## Runtime

| Package | Locked version | Relevant Packagist requirement | Upstream documentation |
| --- | ---: | --- | --- |
| [`laravel/framework`](https://packagist.org/packages/laravel/framework) | 13.34.0 | PHP `^8.3`; the application narrows PHP to `^8.4.1` and Laravel to `^13.34`. | [Laravel 13](https://laravel.com/framework/docs/13.x) |
| [`filament/filament`](https://packagist.org/packages/filament/filament) | 5.9.0 | PHP `^8.2`; this lock uses Filament 5 components. | [Filament 5](https://filamentphp.com/docs/5.x/introduction/installation) |
| [`nwidart/laravel-modules`](https://packagist.org/packages/nwidart/laravel-modules) | 13.0.0 | PHP `^8.3`; requires DOM, JSON, SimpleXML, and `wikimedia/composer-merge-plugin` `^2.1`. | [Laravel Modules 13 install and autoload](https://laravelmodules.com/docs/13/getting-started/installation-and-setup) |
| [`coolsam/modules`](https://packagist.org/packages/coolsam/modules) | 5.3.2 | PHP `^8.3`; supports Filament `^4.0` or `^5.0`, Laravel components 11–13, and Laravel Modules 11–13. | [Coolsam Filament module plugin](https://filamentphp.com/plugins/coolsam-modules) |
| [`laravel/passport`](https://packagist.org/packages/laravel/passport) | 13.8.0 | PHP `^8.2`; Laravel components 11.35+, 12, or 13. | [Passport 13](https://laravel.com/framework/docs/13.x/passport) |
| [`laravel/scout`](https://packagist.org/packages/laravel/scout) | 11.8.0 | PHP `^8.0`; Laravel components 9–13. | [Scout](https://laravel.com/framework/docs/13.x/scout) |
| [`spatie/laravel-backup`](https://packagist.org/packages/spatie/laravel-backup) | 10.3.3 | PHP `^8.3`; Laravel components 12.40+ or 13; ZIP extension `^1.14.0`. MySQL dump creation also requires the `mysqldump` executable. | [Laravel Backup 10](https://spatie.be/docs/laravel-backup/v10/introduction) |
| [`spatie/laravel-permission`](https://packagist.org/packages/spatie/laravel-permission) | 8.3.0 | PHP `^8.3`; Laravel components 12 or 13. | [Laravel Permission 8](https://spatie.be/docs/laravel-permission/v8/introduction) |
| [`spatie/laravel-activitylog`](https://packagist.org/packages/spatie/laravel-activitylog) | 5.1.1 | PHP `^8.4`; Laravel components 12 or 13. | [Laravel Activitylog 5](https://spatie.be/docs/laravel-activitylog/v5/introduction) |
| [`spatie/laravel-settings`](https://packagist.org/packages/spatie/laravel-settings) | 3.9.0 | PHP `^8.2`; Laravel components 11–13. | [Laravel Settings 3](https://spatie.be/docs/laravel-settings/v3/introduction) |
| [`laravel/ai`](https://packagist.org/packages/laravel/ai) | 1.0.1 | PHP `^8.3`; Laravel components 12 or 13, including `illuminate/json-schema` 12.62+ or 13.15+. | [Laravel AI SDK](https://laravel.com/framework/docs/13.x/ai-sdk) |
| [`laravel/mcp`](https://packagist.org/packages/laravel/mcp) | 1.0.1 | PHP `^8.2`; Laravel components 11.45.3+, 12.41.1+, or 13. | [Laravel MCP](https://laravel.com/framework/docs/13.x/mcp) |

The Filament plugin directory's automated badge still says Coolsam Modules has no Filament 5 support; the package's v5 documentation lists Filament 4 and 5, and the locked `coolsam/modules` 5.3.2 Packagist metadata accepts `filament/filament` `^5.0`. Keep the exact Composer constraints and app integration tests as the compatibility check when upgrading.

The effective PHP floor is **8.4.1**. The root project declares `^8.4.1`, Activitylog 5.1.1 and Pest 5 require PHP 8.4, and the locked Symfony Process 8.1.7 and Console 8.1.8 declare PHP `>=8.4.1`. Laravel 13's lower framework floor does not lower this application's composed runtime requirement.

## Development and frontend

| Package | Locked version | Relevant Packagist requirement | Upstream documentation |
| --- | ---: | --- | --- |
| [`pestphp/pest`](https://packagist.org/packages/pestphp/pest) | 5.2.1 | PHP `^8.4`; the lock uses PHPUnit 13 and Symfony Process 8.1.7. | [Pest](https://pestphp.com/docs/installation) |
| [`pestphp/pest-plugin-laravel`](https://packagist.org/packages/pestphp/pest-plugin-laravel) | 5.0.1 | PHP `^8.4`; Laravel `^13.23.0` and Pest `^5.0.1`. | [Pest Laravel plugin](https://github.com/pestphp/pest-plugin-laravel) |
| [`laravel/pint`](https://packagist.org/packages/laravel/pint) | 1.32.1 | PHP `^8.3.0`. | [Laravel Pint](https://laravel.com/docs/13.x/pint) |
| [`larastan/larastan`](https://packagist.org/packages/larastan/larastan) | 3.12.2 | PHP `^8.2`; Laravel components 11.44.2+, 12.4.1+, or 13. | [Larastan](https://github.com/larastan/larastan) |
| [`laravel/boost`](https://packagist.org/packages/laravel/boost) | 2.10.0 | PHP `^8.2`; Laravel components 11.45.3+, 12.41.1+, or 13. It is a development dependency. | [Laravel Boost](https://laravel.com/framework/docs/13.x/boost) |
| [`laravel/telescope`](https://packagist.org/packages/laravel/telescope) | 5.25.0 | PHP `^8.0`; supports Laravel 8.37 through 13. It is a development dependency. | [Laravel Telescope](https://laravel.com/framework/docs/13.x/telescope) |

The host frontend is locked by `package-lock.json`; [`package.json`](../package.json) requires Node `>=24 <25`. Build with `npm ci` followed by `npm run build`. Catalog assets use a separate package lock and Sass 1.105.1; run `npm ci` and `npm run build:assets` from `Modules/Catalog/` so its compiled CSS, JavaScript, and content-hash manifest stay aligned with module sources.

Laravel Boost's testing guidance is generic and some published examples target earlier Pest majors. This application locks Pest 5.2.1; verify test APIs against the installed Pest 5 documentation rather than copying Pest 3/4-specific examples.

## Runtime services and extensions

PHP extensions explicitly required by the root Composer manifest are DOM, fileinfo, JSON, mbstring, MySQLi, PDO, PDO MySQL, Redis, SimpleXML, and ZIP. Catalog uses both Laravel's PDO connection for its migration and MySQLi for its existing SQL workflows. The application targets MySQL 8+ and Redis 7+ in its deployment environment; these server versions are operational requirements rather than Composer package constraints. CI also installs `intl`, `bcmath`, `pcntl`, `curl`, Sodium, and XML extensions used by the build and integration checks.

## Adding or upgrading packages

Before changing a direct dependency, check both the upstream versioned documentation and that exact release's Packagist requirements. Confirm the installed versions with `composer show --direct`, update `composer.json` and `composer.lock` together, run `composer validate --strict` and `composer audit`, then run the application checks in [development](development.md). Do not copy an installation command from an older major-version guide into this app without checking its declared Laravel, PHP, and peer-package ranges.
