<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Swoole\Async;
use App\Shared\Infrastructure\Swoole\CoroutineCanceledException;
use App\Shared\Infrastructure\Swoole\Drain\SwooleHooks;

/** Complete native DNS answers from a bounded, fresh PHP process. */
final class NativeDnsResolver
{
    private const int MAX_CHILDREN = 8;
    private const int MAX_OUTPUT_BYTES = 65_536;
    private const float POLL_SECONDS = 0.005;
    private const float CLEANUP_SECONDS = 0.25;
    private const string CLOSE_DESCRIPTORS = 'for fd in /proc/$$/fd/*; do fd=${fd##*/}; '
        . 'if [ "$fd" -gt 2 ]; then eval "exec $fd>&-"; fi; done; exec "$@"';

    private static int $ownerPid = 0;
    /** @var array<int, array{process: resource, output: resource, buffer: string, abandoned: bool}> */
    private static array $children = [];

    /** The optional path is trusted application configuration, never a destination input. */
    public function __construct(private readonly ?string $helperPath = null)
    {
    }

    /** @return list<string> Complete A and AAAA addresses; classification belongs to the caller. */
    public function resolve(string $hostname, float $deadline): array
    {
        if (!is_finite($deadline) || $deadline <= hrtime(true) / 1e9) {
            throw new \RuntimeException('DNS lookup deadline exceeded.');
        }
        if (!preg_match('/\A[a-z0-9.-]{1,253}\z/iD', $hostname)) {
            throw new \RuntimeException('DNS lookup failed.');
        }
        self::resetInheritedOwnership();
        foreach (array_keys(self::$children) as $pid) {
            if (self::$children[$pid]['abandoned']) {
                self::reap($pid);
            }
        }
        if (count(self::$children) >= self::MAX_CHILDREN) {
            throw new \RuntimeException('DNS lookup capacity exhausted.');
        }
        $pid = $this->start($hostname, $deadline);
        try {
            while (true) {
                if (hrtime(true) / 1e9 >= $deadline) {
                    throw new \RuntimeException('DNS lookup deadline exceeded.');
                }
                self::read($pid);
                $status = self::status($pid);
                if (!$status['running']) {
                    self::read($pid);
                    $reply = self::$children[$pid]['buffer'];
                    $exit = $status['exitcode'];
                    self::release($pid);
                    if ($exit !== 0) {
                        throw new \RuntimeException('DNS lookup failed.');
                    }
                    return self::decode($reply);
                }
                Async::sleep(min(self::POLL_SECONDS, max(0.001, $deadline - hrtime(true) / 1e9)));
            }
        } finally {
            if (isset(self::$children[$pid])) {
                self::$children[$pid]['abandoned'] = true;
                self::native(static fn () => proc_terminate(self::$children[$pid]['process'], SIGKILL));
                if (Async::inCoroutine() && \Swoole\Coroutine::isCanceled()) {
                    self::handoff($pid);
                } else {
                    try {
                        self::cleanup($pid);
                    } catch (CoroutineCanceledException $cancelled) {
                        self::handoff($pid);
                        throw $cancelled;
                    }
                }
            }
        }
    }

    private function start(string $hostname, float $deadline): int
    {
        $helper = $this->helperPath ?? dirname(__DIR__, 4) . '/bin/dns-lookup.php';
        $lifetime = max(1, min(30, (int) ceil($deadline - hrtime(true) / 1e9)));
        // No yield while hooks are suspended or before ownership is registered.
        return self::native(static function () use ($helper, $hostname, $lifetime): int {
            $process = proc_open(
                ['/bin/bash', '-c', self::CLOSE_DESCRIPTORS, 'dns-lookup', PHP_BINARY,
                    '-d', 'memory_limit=32M', '-d', 'display_errors=0', '-d', 'log_errors=0',
                    '-d', 'auto_prepend_file=', '-d', 'auto_append_file=', $helper, $hostname, (string) $lifetime],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
                null,
                [],
            );
            if (!is_resource($process)) {
                throw new \RuntimeException('DNS lookup failed.');
            }
            stream_set_blocking($pipes[1], false);
            $pid = proc_get_status($process)['pid'];
            self::$children[$pid] = ['process' => $process, 'output' => $pipes[1], 'buffer' => '', 'abandoned' => false];
            return $pid;
        });
    }

    private static function resetInheritedOwnership(): void
    {
        $pid = getmypid();
        if (self::$ownerPid !== $pid) {
            // Inherited handles do not own children in this process. Never signal their PIDs.
            self::native(static function (): void {
                self::$children = [];
            });
            self::$ownerPid = $pid;
        }
    }

    private static function read(int $pid): void
    {
        $child = &self::$children[$pid];
        $chunk = self::native(static fn () => stream_get_contents($child['output'], self::MAX_OUTPUT_BYTES + 1 - strlen($child['buffer'])));
        if ($chunk === false) {
            throw new \RuntimeException('DNS lookup failed.');
        }
        $child['buffer'] .= $chunk;
        if (strlen($child['buffer']) > self::MAX_OUTPUT_BYTES) {
            throw new \RuntimeException('DNS lookup reply exceeded its limit.');
        }
    }

    /** @return list<string> */
    private static function decode(string $reply): array
    {
        try {
            $addresses = json_decode($reply, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('DNS lookup failed.');
        }
        if (!is_array($addresses) || !array_is_list($addresses) || $addresses === []) {
            throw new \RuntimeException('DNS lookup failed.');
        }
        foreach ($addresses as $address) {
            if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new \RuntimeException('DNS lookup failed.');
            }
        }
        return $addresses;
    }

    private static function handoff(int $pid): void
    {
        // A fresh coroutine is not canceled with its requester. No process-wide timer survives it.
        \Swoole\Coroutine::create(static function () use ($pid): void {
            self::cleanup($pid);
        });
    }

    private static function cleanup(int $pid): void
    {
        $until = hrtime(true) / 1e9 + self::CLEANUP_SECONDS;
        while (!self::reap($pid) && hrtime(true) / 1e9 < $until) {
            Async::sleep(self::POLL_SECONDS);
        }
        // An abnormal kernel delay retains ownership and admission; later calls can reap it.
    }

    private static function reap(int $pid): bool
    {
        if (!isset(self::$children[$pid])) {
            return true;
        }
        if (self::status($pid)['running']) {
            return false;
        }
        self::release($pid);
        return true;
    }

    /** @return array{running: bool, exitcode: int} */
    private static function status(int $pid): array
    {
        return self::native(static fn () => proc_get_status(self::$children[$pid]['process']));
    }

    /** @template T
     * @param \Closure(): T $action
     * @return T
     */
    private static function native(\Closure $action): mixed
    {
        // Swoole can yield in descriptor setup and reads even on native nonblocking pipes.
        // Keep these short OS calls native; only the explicit polling sleep may yield.
        return SwooleHooks::without(\defined('SWOOLE_HOOK_ALL') ? \SWOOLE_HOOK_ALL : 0, $action);
    }

    private static function release(int $pid): void
    {
        self::native(static function () use ($pid): void {
            fclose(self::$children[$pid]['output']);
            // Only called after stopped status: proc_close never waits for a running child.
            proc_close(self::$children[$pid]['process']);
            unset(self::$children[$pid]);
        });
    }
}
