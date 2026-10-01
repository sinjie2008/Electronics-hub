# Modules

The repository uses [Laravel Modules 13](https://laravelmodules.com/docs/13/getting-started/installation-and-setup) with the [Coolsam Filament Modules plugin](https://filamentphp.com/plugins/coolsam-modules). Modules live beneath `Modules/`, but each module is a Composer package boundary rather than a directory covered by a root `Modules\` PSR-4 rule.

## Baseline modules

| Module | Responsibility |
| --- | --- |
| `Core` | Shared foundation and application-wide conventions. |
| `IAM` | Users, roles, permissions, and access-control policies. |
| `System` | Administrative system settings, activity, and backup operations. |

The `Core`, `IAM`, and `System` modules are protected and must stay enabled. The protected activator enforces that invariant when module state changes. Do not edit `modules_statuses.json` to bypass it. Run `php artisan module:list` to inspect module state.

Optional-module activation is runtime state recorded in `modules_statuses.json`. The deployment must make that file writable by the process that changes module state and ensure every application instance reads the same state. For a read-only release directory, set `MODULE_STATUSES_PATH` to a writable shared JSON file outside the release and initialize it from the tracked baseline. Changes are serialized with file locks and written atomically; failed audit persistence compensates the state change. If consistent shared filesystem state cannot be guaranteed, restrict optional-module changes to deployment releases. The Filament page only lists, enables, and disables installed module code; it must not run Composer installs or updates, migrations, deployment scripts, or cache rebuilds from a web request.

## Autoloading and structure

The root `composer.json` merges `Modules/*/composer.json` with `wikimedia/composer-merge-plugin`. Each module declares its own PSR-4 namespace and any module-level Composer metadata. This follows Laravel Modules 13's package-autoloading model; do not add a root-wide `Modules/` PSR-4 mapping. After changing a module's Composer metadata, refresh autoloading with `composer dump-autoload` and commit the resulting dependency/lock changes where applicable.

Use the module's existing directory conventions for providers, policies, Filament code, routes, migrations, seeders, resources, and tests. Keep shared application shell concerns in the root `app/` tree. A module should communicate through public classes and service contracts, not another module's internal implementation.

## Filament plugins

Filament module UI is registered through Coolsam in plugin mode. Module plugin classes such as `IAMPlugin` and `SystemPlugin` are discovered by the admin panel's module integration. The installed ModuleFilamentPlugin trait checks that a module is enabled before discovering its resources, pages, widgets, and Livewire components. Composer autoloading makes a class available even when its module is disabled.

When adding a module-owned resource, page, or widget, keep it inside that module's Filament namespace and register it through the module plugin. Protect the page or resource with the relevant application policy and permission; hiding a navigation item does not authorize a request.

## Adding an optional module

Create the module in source control and deploy it with the application. The installed package exposes the Laravel Modules and Coolsam generators; verify the exact options in the installed version before scripting a generator:

```bash
php artisan module:make Reporting
php artisan module:make:filament-plugin Reporting Reporting
```

Add the module's `composer.json` autoload mapping, then run `composer dump-autoload`. Add provider, migrations, permissions, a Filament plugin, and tests as needed. Test disabled-module behavior as well as the enabled path, then deploy the module code and migrations before activating it. The admin page enforces application permissions and dependency checks and writes an audit record. A controlled release can use `php artisan module:enable Reporting` to update `modules_statuses.json` when that state belongs in every deployment; this package command does not call the application dependency checks or audit wrapper, so validate dependencies and record the release action through the deployment process. The protected activator refuses to disable any baseline module.

In deployment, install the committed lock file and ship the module source with the application. Do not depend on a module existing only in a developer's working tree. After changing module files, plugins, providers, or activation state, clear and rebuild Laravel's route/configuration/view caches and Filament's component caches as part of the release, then restart long-lived queue workers. If the activation file is writable at runtime, rebuild these caches through the deployment pipeline after toggles; the web page does not perform deployment work. For a separately versioned module package, update the project's package and autoload policy deliberately and verify it against [the compatibility matrix](package-compatibility.md).
