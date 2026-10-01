<?php

declare(strict_types=1);

namespace App\Modules;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Validation\ValidationException;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Nwidart\Modules\Module;
use RuntimeException;
use Throwable;

final class ProtectedModuleActivator implements ActivatorInterface
{
    public const PROTECTED = ['Core', 'IAM', 'System'];

    private string $statusesFile;

    private int $lockDepth = 0;

    public function __construct(Container $app)
    {
        $this->statusesFile = $app['config']->get('modules.activators.file.statuses-file');
    }

    public function getStatusesFilePath(): string
    {
        return $this->statusesFile;
    }

    public function enable(Module $module): void
    {
        $this->setActive($module, true);
    }

    public function disable(Module $module): void
    {
        $this->setActive($module, false);
    }

    public function setActive(Module $module, bool $active): void
    {
        $this->setActiveByName($module->getName(), $active);
    }

    public function hasStatus(Module|string $module, bool $status): bool
    {
        $name = $module instanceof Module ? $module->getName() : $module;
        if ($this->isProtected($name)) {
            return $status;
        }

        return $this->locked(function () use ($name, $status): bool {
            $statuses = $this->read();
            $key = $this->canonicalName($name, $statuses);

            return ($statuses[$key] ?? false) === $status;
        }, LOCK_SH);
    }

    public function setActiveByName(string $name, bool $active): void
    {
        if (! $active && $this->isProtected($name)) {
            throw ValidationException::withMessages(['module' => 'Foundational modules cannot be disabled.']);
        }
        $this->locked(function () use ($name, $active): void {
            $statuses = $this->read();
            $statuses[$this->canonicalName($name, $statuses)] = $active;
            $this->write($statuses);
        });
    }

    public function delete(Module $module): void
    {
        if ($this->isProtected($module->getName())) {
            throw ValidationException::withMessages(['module' => 'Foundational modules cannot be removed.']);
        }
        $this->locked(function () use ($module): void {
            $statuses = $this->read();
            unset($statuses[$this->canonicalName($module->getName(), $statuses)]);
            $this->write($statuses);
        });
    }

    public function reset(): void
    {
        $this->locked(fn () => $this->write(array_fill_keys(self::PROTECTED, true)));
    }

    /** Serialize graph validation, persistence and audit; compensate if the operation fails. */
    public function transaction(Closure $operation): mixed
    {
        return $this->locked(function () use ($operation): mixed {
            $before = $this->read();
            try {
                return $operation();
            } catch (Throwable $exception) {
                if ($this->read() !== $before) {
                    $this->write($before);
                }
                throw $exception;
            }
        });
    }

    /** @return array<string, bool> */
    private function read(): array
    {
        if (! is_file($this->statusesFile)) {
            if (file_exists($this->statusesFile)) {
                throw new RuntimeException('The module status path must be a writable JSON file.');
            }

            return [];
        }
        $contents = file_get_contents($this->statusesFile);
        if ($contents === false) {
            throw new RuntimeException('Cannot read module statuses.');
        }
        $statuses = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($statuses)) {
            throw new RuntimeException('Invalid module status JSON.');
        }
        foreach ($statuses as $name => $enabled) {
            if (! is_string($name) || ! is_bool($enabled)) {
                throw new RuntimeException('Module statuses must map module names to booleans.');
            }
        }

        return $statuses;
    }

    /** @param array<string, bool> $statuses */
    private function write(array $statuses): void
    {
        $contents = json_encode($statuses, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
        $temporary = tempnam(dirname($this->statusesFile), '.module-status-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create a module status file.');
        }
        try {
            if (file_put_contents($temporary, $contents) !== strlen($contents)
                || ! chmod($temporary, 0660) || ! rename($temporary, $this->statusesFile)) {
                throw new RuntimeException('Cannot persist module statuses.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function isProtected(string $name): bool
    {
        return in_array(strtolower($name), array_map(strtolower(...), self::PROTECTED), true);
    }

    /** @param array<string, bool> $statuses */
    private function canonicalName(string $name, array $statuses): string
    {
        foreach ([...self::PROTECTED, ...array_keys($statuses)] as $canonical) {
            if (strcasecmp($canonical, $name) === 0) {
                return $canonical;
            }
        }

        return $name;
    }

    private function locked(Closure $operation, int $mode = LOCK_EX): mixed
    {
        if ($this->lockDepth > 0) {
            return $operation();
        }
        $lock = fopen($this->statusesFile.'.lock', 'c');
        if ($lock === false || ! flock($lock, $mode)) {
            throw new RuntimeException('The module status directory must be writable.');
        }
        $this->lockDepth++;
        try {
            return $operation();
        } finally {
            $this->lockDepth--;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
