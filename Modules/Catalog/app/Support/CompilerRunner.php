<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use Modules\Catalog\Http\CatalogApiException;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/** Runs untrusted document programs with a private filesystem and no network. */
final class CompilerRunner
{
    public const MAX_SOURCE_BYTES = 1048576;

    public const MAX_FILE_BYTES = 33554432;

    private const MAX_OUTPUT_BYTES = 262144;

    public static function assertModuleEnabled(): void
    {
        if (! app('modules')->isEnabled('Catalog')) {
            throw new CatalogApiException('MODULE_DISABLED', 'Catalog is disabled.', 404);
        }
    }

    public static function assertSourceSize(string $source): void
    {
        if (strlen($source) > self::MAX_SOURCE_BYTES) {
            throw new CatalogApiException('COMPILER_INPUT_TOO_LARGE', 'Document source exceeds the compilation limit.', 422);
        }
    }

    public static function createJobDirectory(string $parent): string
    {
        if (! is_dir($parent) && ! mkdir($parent, 0700, true) && ! is_dir($parent)) {
            throw new RuntimeException('Unable to create compiler storage.');
        }
        $directory = $parent.'/job_'.bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Unable to create compiler workspace.');
        }

        return realpath($directory) ?: $directory;
    }

    /** Delete only the newly created job, without following filesystem links. */
    public static function cleanup(string $directory): void
    {
        if (! is_dir($directory) || is_link($directory)) {
            return;
        }
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file->isDir() && ! $file->isLink()) {
                self::cleanup($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($directory);
    }

    /** @return array{stdout: string, stderr: string, exitCode: int} */
    public function run(string $binary, array $arguments, string $directory): array
    {
        self::assertModuleEnabled();
        $finder = new ExecutableFinder;
        $sandbox = $finder->find('bwrap', null, ['/usr/bin', '/bin']);
        $limiter = $finder->find('prlimit', null, ['/usr/bin', '/bin']);
        $executable = str_contains($binary, '/') ? $binary : $finder->find($binary, null, ['/usr/local/bin', '/usr/bin', '/bin']);
        if (PHP_OS_FAMILY !== 'Linux' || $sandbox === null || $limiter === null) {
            throw new CatalogApiException('COMPILER_UNAVAILABLE', 'Secure document compilation requires Linux bubblewrap and prlimit.', 503);
        }
        if (! is_string($executable) || ! is_file($executable) || ! is_executable($executable)) {
            throw new CatalogApiException('COMPILER_UNAVAILABLE', 'The document compiler is unavailable.', 503);
        }

        $command = [$sandbox, '--unshare-all', '--unshare-user', '--die-with-parent', '--new-session', '--cap-drop', 'ALL', '--disable-userns', '--clearenv'];
        $runtimeDirectories = [
            '/usr/bin', '/bin', '/lib', '/lib64', '/usr/lib', '/usr/lib64',
            '/usr/share/texlive', '/usr/share/texmf', '/usr/share/fonts', '/usr/share/fontconfig',
            '/usr/local/share/fonts', '/etc/fonts', '/etc/texmf', '/var/lib/texmf', '/var/cache/fontconfig',
        ];
        foreach ($runtimeDirectories as $path) {
            if (is_dir($path)) {
                array_push($command, '--ro-bind', $path, $path);
            }
        }
        if (! str_starts_with($executable, '/usr/bin/') && ! str_starts_with($executable, '/bin/')) {
            $sandboxExecutable = '/compiler-bin/'.basename($executable);
            array_push($command, '--ro-bind', realpath($executable) ?: $executable, $sandboxExecutable);
            $executable = $sandboxExecutable;
        }
        array_push($command, '--proc', '/proc', '--dev', '/dev', '--bind', $directory, '/work', '--chdir', '/work');
        foreach ([
            'PATH' => '/usr/bin:/bin', 'HOME' => '/work', 'TMPDIR' => '/work', 'LANG' => 'C.UTF-8',
            'openin_any' => 'p', 'openout_any' => 'p', 'shell_escape' => 'f',
            'TEXMFOUTPUT' => '/work', 'TEXMFVAR' => '/work/texmf-var', 'TEXMFCONFIG' => '/work/texmf-config',
            'TYPST_PACKAGE_PATH' => '/work/packages', 'TYPST_PACKAGE_CACHE_PATH' => '/work/packages',
        ] as $name => $value) {
            array_push($command, '--setenv', $name, $value);
        }
        array_push($command, '--', $executable, ...$arguments);
        $command = [$limiter, '--cpu=30', '--as=1073741824', '--fsize='.self::MAX_FILE_BYTES, '--nofile=128', '--', ...$command];

        /** Symfony merges inherited variables unless each is explicitly removed. */
        $environment = array_fill_keys(array_keys(array_merge(getenv(), $_ENV, $_SERVER)), false);
        $environment['PATH'] = '/usr/bin:/bin';
        $process = new Process($command, $directory, $environment, null, 35);
        $stdout = '';
        $stderr = '';
        $bytes = 0;
        $outputLimitExceeded = false;
        try {
            $process->start(function (string $type, string $buffer) use (&$stdout, &$stderr, &$bytes, &$outputLimitExceeded, $process): void {
                $bytes += strlen($buffer);
                if ($bytes > self::MAX_OUTPUT_BYTES) {
                    $outputLimitExceeded = true;
                }
                if ($type === Process::OUT) {
                    if (! $outputLimitExceeded) {
                        $stdout .= $buffer;
                    }
                    $process->clearOutput();
                } else {
                    if (! $outputLimitExceeded) {
                        $stderr .= $buffer;
                    }
                    $process->clearErrorOutput();
                }
            });
            while ($process->isRunning()) {
                $process->checkTimeout();
                if ($outputLimitExceeded) {
                    throw new CatalogApiException('COMPILER_LIMIT_EXCEEDED', 'Compiler output exceeded the compilation limit.', 422);
                }
                $this->assertWorkspaceSize($directory);
                usleep(20000);
            }
            if ($outputLimitExceeded) {
                throw new CatalogApiException('COMPILER_LIMIT_EXCEEDED', 'Compiler output exceeded the compilation limit.', 422);
            }
            $this->assertWorkspaceSize($directory);
            if (str_contains($stderr, 'bwrap:')) {
                throw new CatalogApiException('COMPILER_UNAVAILABLE', 'Secure compiler isolation is unavailable on this host.', 503);
            }

            return ['stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => $process->getExitCode() ?? 1];
        } catch (CatalogApiException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new CatalogApiException('COMPILER_LIMIT_EXCEEDED', 'The compiler could not complete within its resource limits.', 422);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }

    private function assertWorkspaceSize(string $directory): void
    {
        $bytes = 0;
        $files = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink() || ++$files > 256) {
                throw new CatalogApiException('COMPILER_LIMIT_EXCEEDED', 'Compiler workspace exceeded the compilation limit.', 422);
            }
            $bytes += $file->getSize();
            if ($file->getSize() > self::MAX_FILE_BYTES || $bytes > 67108864) {
                throw new CatalogApiException('COMPILER_LIMIT_EXCEEDED', 'Compiler files exceeded the compilation limit.', 422);
            }
        }
    }
}
