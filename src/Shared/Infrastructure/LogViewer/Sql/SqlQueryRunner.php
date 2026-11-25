<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LogViewer\Sql;

use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Infrastructure\Swoole\Async;
use App\Shared\Infrastructure\Swoole\Drain\SwooleHooks;
use Closure;
use Swoole\Runtime;

/** Isolated child adapter; coroutine execution requires the Swoole process hooks. */
final readonly class SqlQueryRunner
{
    public function __construct(private float $maxSeconds = 5.0, private ?Closure $cancelled = null)
    {
    }

    /** @param list<array<string, mixed>> $records
     * @return array{columns:list<string>,rows:list<list<mixed>>,truncated:bool}
     */
    public function run(string $sql, array $records): array
    {
        if (Async::inCoroutine() && (Runtime::getHookFlags() & SWOOLE_HOOK_PROC) === 0) {
            throw new ServiceUnavailableException('SQL process hooks are unavailable.');
        }
        try {
            SqlStatement::validate($sql);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidInputException($e->getMessage(), previous: $e);
        }
        $directory = sys_get_temp_dir().'/baander-log-sql-'.bin2hex(random_bytes(16));
        if (!mkdir($directory, 0700)) {
            throw new ServiceUnavailableException('Cannot create SQL snapshot workspace.');
        }
        $process = null;
        $pipes = [];
        $descriptors = [];
        try {
            $input = $directory.'/request.json';
            $data = json_encode(['sql' => $sql, 'records' => $records], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            if (file_put_contents($input, $data) !== strlen($data)) {
                throw new ServiceUnavailableException('Cannot write SQL snapshot.');
            }
            chmod($input, 0600);
            $response = $directory.'/response.json';
            // Passing open resources avoids Swoole file-wrapper descriptor ownership races.
            $descriptors = [fopen('/dev/null', 'r'), fopen($response, 'w'), fopen('/dev/null', 'w')];
            $process = proc_open([PHP_BINARY, '-d', 'memory_limit=256M', '-d', 'display_errors=0', '-d', 'log_errors=0', dirname(__DIR__, 5).'/bin/log-query.php', $input], $descriptors, $pipes, $directory, []);
            if (!is_resource($process)) {
                throw new ServiceUnavailableException('Cannot start SQL query process.');
            }
            $deadline = hrtime(true) + (int) ($this->maxSeconds * 1_000_000_000);
            $output = '';
            while (true) {
                Async::throwIfCanceled();
                $status = proc_get_status($process);
                if ($this->cancelled !== null && ($this->cancelled)()) {
                    throw new ServiceUnavailableException('SQL query cancelled.', ['reason' => 'cancelled']);
                }
                if (hrtime(true) >= $deadline) {
                    throw new ServiceUnavailableException('SQL query exceeded five seconds.', ['reason' => 'timeout']);
                }
                // These bounded local metadata reads must not yield into uncancellable file I/O.
                $memory = SwooleHooks::without(defined('SWOOLE_HOOK_FILE') ? SWOOLE_HOOK_FILE : 0, static fn (): string|false => @file_get_contents('/proc/'.$status['pid'].'/status'));
                if (is_string($memory) && preg_match('/^VmRSS:\s+(\d+) kB/m', $memory, $match) === 1 && (int) $match[1] > 262144) {
                    throw new ServiceUnavailableException('SQL query exceeded 256 MiB.', ['reason' => 'memory']);
                }
                $size = SwooleHooks::without(defined('SWOOLE_HOOK_FILE') ? SWOOLE_HOOK_FILE : 0, static function () use ($response): int|false {
                    clearstatcache(true, $response);
                    return filesize($response);
                });
                if ($size !== false && $size > 4194304) {
                    throw new ServiceUnavailableException('SQL query exceeded the output limit.', ['reason' => 'output']);
                }
                if (!$status['running']) {
                    $output = file_get_contents($response, length: 4194305);
                    break;
                }
                Async::sleep(0.001);
            }
            $reply = json_decode($output, true);
            if (!is_array($reply)) {
                throw new ServiceUnavailableException('SQL query process failed.', ['reason' => 'process']);
            }

            return self::unwrap($reply);
        } finally {
            if (is_resource($process)) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
            foreach ($descriptors as $descriptor) {
                if (is_resource($descriptor)) { fclose($descriptor); }
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    /** @param array<string, mixed> $reply
     * @return array{columns:list<string>,rows:list<list<mixed>>,truncated:bool}
     */
    private static function unwrap(array $reply): array
    {
        if (($reply['ok'] ?? false) === true && is_array($reply['result'] ?? null)) {
            return $reply['result'];
        }
        if (($reply['kind'] ?? '') === 'invalid') {
            throw new InvalidInputException((string) ($reply['message'] ?? 'SQL query rejected.'));
        }
        throw new ServiceUnavailableException((string) ($reply['message'] ?? 'SQL query failed.'), is_array($reply['details'] ?? null) ? $reply['details'] : []);
    }
}
