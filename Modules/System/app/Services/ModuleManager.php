<?php

declare(strict_types=1);

namespace Modules\System\Services;

use App\Models\User;
use App\Modules\ProtectedModuleActivator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Nwidart\Modules\Contracts\RepositoryInterface;
use Nwidart\Modules\Module;

final readonly class ModuleManager
{
    public function __construct(private RepositoryInterface $modules) {}

    /** @return array<int, array<string, mixed>> */
    public function list(User $actor): array
    {
        Gate::forUser($actor)->authorize('modules.view');

        return array_values(array_map(fn (Module $module): array => [
            'name' => $module->getName(),
            'description' => (string) $module->get('description', ''),
            'version' => (string) $module->get('version', 'Unspecified'),
            'enabled' => $module->isEnabled(),
            'path' => $module->getPath(),
            'dependencies' => $this->dependencies($module),
            'protected' => in_array($module->getName(), ProtectedModuleActivator::PROTECTED, true),
        ], $this->modules->all()));
    }

    public function enable(User $actor, string $name): void
    {
        Gate::forUser($actor)->authorize('modules.enable');
        $this->change(function () use ($actor, $name): void {
            $module = $this->modules->findOrFail($name);
            foreach ($this->dependencies($module) as $dependency) {
                $required = $this->modules->find($dependency);
                if (! $required || ! $required->isEnabled()) {
                    throw ValidationException::withMessages(['module' => "Enable the {$dependency} dependency first."]);
                }
            }
            if (! $module->isEnabled()) {
                $module->enable();
                $this->audit($actor, $module, 'module.enabled');
            }
        });
    }

    public function disable(User $actor, string $name): void
    {
        Gate::forUser($actor)->authorize('modules.disable');
        $this->change(function () use ($actor, $name): void {
            $module = $this->modules->findOrFail($name);
            $canonicalName = $module->getName();
            if (in_array($canonicalName, ProtectedModuleActivator::PROTECTED, true)) {
                throw ValidationException::withMessages(['module' => 'Foundational modules cannot be disabled.']);
            }
            foreach ($this->modules->allEnabled() as $dependent) {
                foreach ($this->dependencies($dependent) as $dependency) {
                    if (strcasecmp($canonicalName, $dependency) === 0) {
                        throw ValidationException::withMessages(['module' => "Disable the dependent {$dependent->getName()} module first."]);
                    }
                }
            }
            if ($module->isEnabled()) {
                $module->disable();
                $this->audit($actor, $module, 'module.disabled');
            }
        });
    }

    private function change(\Closure $operation): void
    {
        $activator = app(ActivatorInterface::class);
        $activator->transaction(fn () => DB::transaction($operation));
    }

    /** @return list<string> */
    private function dependencies(Module $module): array
    {
        $dependencies = $module->get('requires', []);

        return is_array($dependencies) ? array_values(array_filter($dependencies, is_string(...))) : [];
    }

    private function audit(User $actor, Module $module, string $event): void
    {
        activity('administration')->causedBy($actor)->event($event)
            ->withProperties(['module' => $module->getName()])->log($event);
    }
}
