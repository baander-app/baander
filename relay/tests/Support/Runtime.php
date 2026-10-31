<?php

declare(strict_types=1);

namespace Baander\RegistryTests;

use RuntimeException;
use Swoole\Process as SwooleProcess;
use Throwable;

final class Runtime
{
    private static ?int $interruptedSignal = null;

    public static function baseline(): void
    {
        self::requireThat(
            version_compare(PHP_VERSION, '8.5.0', '>=') && extension_loaded('swoole'),
            'Registry harnesses require PHP 8.5+ with Swoole.',
        );
    }
    public static function requireThat(bool $condition, string $message = 'Contract assertion failed.'): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
    /**
     * @param list<string> $argv
     * @param array<string,string>|null $env
     * @return array{code:int,stdout:string,stderr:string}
     */
    public static function run(array $argv, ?string $input = null, float $timeout = 30, ?array $env = null): array
    {
        $process = new Process($argv, env: $env);
        try {
            $process->input($input ?? '');
            $code = $process->wait($timeout);
            return ['code' => $code, 'stdout' => $process->stdout, 'stderr' => $process->stderr];
        } finally {
            $process->close(false);
        }
    }
    /**
     * @param list<string> $argv
     * @return string
     */
    public static function checked(array $argv, ?string $input = null, float $timeout = 30): string
    {
        $result = self::run($argv, $input, $timeout);
        self::requireThat(
            $result['code'] === 0,
            'Command failed (' . $result['code'] . '): ' . json_encode($argv) . "\n" . $result['stderr'],
        );
        return $result['stdout'];
    }
    public static function temporaryDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(8));
        self::requireThat(mkdir($directory, 0700), 'Cannot create temporary directory.');
        return $directory;
    }
    public static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            self::requireThat(unlink($path), 'Cannot remove ' . $path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path) as $entry) {
            self::removeTree($entry->getPathname());
        }
        self::requireThat(rmdir($path), 'Cannot remove directory ' . $path);
    }
    /**
     * @return resource
     */
    public static function reservePort()
    {
        self::requireThat(extension_loaded('sockets'), 'Registry cluster port reservations require ext-sockets.');
        $socket = socket_create(AF_INET, SOCK_STREAM | SOCK_CLOEXEC, SOL_TCP);
        self::requireThat($socket !== false && socket_bind($socket, '127.0.0.1', 0), 'Cannot reserve loopback port.');
        $stream = socket_export_stream($socket);
        self::requireThat($stream !== false, 'Cannot export loopback port reservation.');
        return $stream;
    }
    /**
     * @param resource $socket
     */
    public static function socketPort($socket): int
    {
        $name = stream_socket_get_name($socket, false);
        self::requireThat($name !== false, 'Cannot inspect reserved socket address.');
        return $name |> (static fn(string $address): string => substr($address, strrpos($address, ':') + 1)) |> intval(...);
    }
    public static function port(): int
    {
        $socket = self::reservePort();
        try {
            return self::socketPort($socket);
        } finally {
            fclose($socket);
        }
    }
    public static function now(): float
    {
        return hrtime(true) / 1000000000.0;
    }
    /**
     * @param list<object|null> $resources
     */
    public static function closeResources(array $resources): void
    {
        $failures = [];
        foreach ($resources as $resource) {
            try {
                $resource?->close();
            } catch (Throwable $failure) {
                $failures[] = $failure;
            }
        }
        if ($failures !== []) {
            throw new CleanupFailure($failures);
        }
    }
    /**
     * @param list<object|null> $resources
     */
    public static function cleanupScope(callable $run, array $resources): mixed
    {
        try {
            $result = $run();
        } catch (Throwable $original) {
            try {
                self::closeResources($resources);
            } catch (Throwable $cleanup) {
                throw new CleanupFailure([$original, $cleanup]);
            }
            throw $original;
        }
        self::closeResources($resources);
        return $result;
    }
    /**
     * @param list<object|null> $resources
     */

    public static function closeAfter(?Throwable $original, array $resources): void
    {
        try {
            self::closeResources($resources);
        } catch (Throwable $cleanup) {
            if ($original !== null) {
                throw new CleanupFailure([$original, $cleanup]);
            }
            throw $cleanup;
        }
    }
    /**
     * @param list<string> $required
     * @param array<string,string|int|bool|null> $defaults
     * @return array<string,string|int|bool|null>
     */
    public static function arguments(array $required, array $defaults = []): array
    {
        global $argv;
        $values = $defaults;
        $allowed = array_fill_keys([...$required, ...array_keys($defaults)], true);
        for ($i = 1; $i < count($argv); ++$i) {
            $argument = $argv[$i];
            if ($argument === '--help' || $argument === '-h') {
                echo 'Options: ' . implode(' ', array_map(static fn(string $name): string => '--' . $name, array_keys($allowed))) . "\n";
                exit(0);
            }
            [$name, $assigned] = array_pad(explode('=', substr($argument, 2), 2), 2, null);
            self::requireThat(
                str_starts_with($argv[$i], '--') && isset($allowed[$name]),
                'Unknown argument: ' . $argv[$i],
            );
            if (is_bool($defaults[$name] ?? null)) {
                self::requireThat($assigned === null, 'Boolean option does not take a value: --' . $name);
                $values[$name] = true;
            } elseif ($assigned !== null) {
                $values[$name] = $assigned;
            } else {
                self::requireThat(
                    isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '--'),
                    'Missing value for --' . $name,
                );
                $values[$name] = $argv[++$i];
            }
        }
        foreach ($required as $name) {
            self::requireThat(isset($values[$name]), 'Required argument: --' . $name);
        }
        return $values;
    }
    /** Runs coroutine work without taking ownership of the harness signal reactor. */
    public static function runCoroutines(callable $run, ?float $timeout = null): void
    {
        self::requireThat(self::coroutineCount() === 0, 'Coroutine scope requires an idle standalone harness.');
        $previousHooks = \Swoole\Runtime::getHookFlags();
        $hooks = SWOOLE_HOOK_ALL;
        if (!extension_loaded('curl')) {
            $hooks &= ~(SWOOLE_HOOK_CURL | SWOOLE_HOOK_NATIVE_CURL);
        }
        try {
            \Swoole\Runtime::setHookFlags($hooks);
            $failure = null;
            $complete = false;
            $deadline = $timeout === null ? null : self::now() + $timeout;
            $task = \Swoole\Coroutine::create(static function () use ($run, &$failure, &$complete): void {
                try {
                    $run();
                } catch (Throwable $error) {
                    $failure ??= $error;
                } finally {
                    $complete = true;
                }
            });
            self::requireThat($task !== false, 'Cannot start coroutine scope.');
            try {
                while (!$complete || self::coroutineCount() !== 0) {
                    if ($complete && $failure !== null) {
                        break;
                    }
                    if ($deadline !== null && self::now() >= $deadline) {
                        throw new RuntimeException('Coroutine scope deadline exceeded.');
                    }
                    self::dispatch();
                }
            } catch (Throwable $error) {
                $failure = $error;
            } finally {
                $cleanupDeadline = self::now() + 2;
                while (self::coroutineCount() !== 0) {
                    foreach (iterator_to_array(\Swoole\Coroutine::list(), false) as $id) {
                        if (\Swoole\Coroutine::exists($id)) {
                            \Swoole\Coroutine::cancel($id);
                        }
                    }
                    if (self::coroutineCount() === 0) {
                        break;
                    }
                    if (self::now() >= $cleanupDeadline) {
                        throw new CleanupFailure([...($failure === null ? [] : [$failure]), new RuntimeException('Owned coroutines did not stop within two seconds.')]);
                    }
                    try {
                        self::dispatch();
                    } catch (Throwable $error) {
                        $failure ??= $error;
                    }
                }
            }
            if ($failure !== null) {
                throw $failure;
            }
        } finally {
            \Swoole\Runtime::setHookFlags($previousHooks);
        }
    }

    /** @phpstan-impure */
    private static function coroutineCount(): int
    {
        return \Swoole\Coroutine::stats()['coroutine_num'];
    }

    private static function dispatch(): void
    {
        $wake = \Swoole\Timer::after(1, static function (): void {});
        try {
            \Swoole\Event::dispatch();
        } finally {
            if (\Swoole\Timer::exists($wake)) {
                \Swoole\Timer::clear($wake);
            }
        }
        self::throwIfInterrupted();
    }
    private static function throwIfInterrupted(): void
    {
        if (self::$interruptedSignal !== null) {
            $signal = self::$interruptedSignal;
            self::$interruptedSignal = null;
            throw new Interrupted($signal);
        }
    }
    public static function main(callable $run): void
    {
        $exitCode = 0;
        try {
            self::baseline();
            self::$interruptedSignal = null;
            $receive = static function (int $signal): void {
                self::$interruptedSignal ??= $signal;
            };
            SwooleProcess::signal(2, $receive);
            SwooleProcess::signal(15, $receive);
            $run();
            self::throwIfInterrupted();
        } catch (Throwable $failure) {
            fwrite(STDERR, $failure . "\n");
            $exitCode = $failure instanceof Interrupted ? 128 + $failure->signal
                : ($failure->getCode() > 0 && $failure->getCode() <= 255 ? $failure->getCode() : 1);
        } finally {
            if (extension_loaded('swoole')) {
                SwooleProcess::signal(2, null);
                SwooleProcess::signal(15, null);
                if (\Swoole\Timer::stats()['num'] === 0) {
                    \Swoole\Event::wait();
                }
            }
        }
        if ($exitCode !== 0) {
            exit($exitCode);
        }
    }
}
