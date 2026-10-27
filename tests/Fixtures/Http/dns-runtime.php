<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Http\NativeDnsResolver;
use App\Shared\Infrastructure\Http\PublicDestinationPolicy;
use App\Shared\Infrastructure\Http\PublicHttpClient;
use App\Shared\Infrastructure\Http\UnsafeDestinationException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return list<int> */
function children(): array
{
    $value = trim((string) file_get_contents('/proc/self/task/' . getmypid() . '/children'));
    return $value === '' ? [] : array_map('intval', explode(' ', $value));
}

if (($_SERVER['argv'][1] ?? '') === 'orphan-parent') {
    Swoole\Coroutine\run(static function (): void {
        Swoole\Coroutine::create(static function (): void {
            $until = hrtime(true) / 1e9 + 1;
            do {
                $pids = children();
                if ($pids !== [] && str_contains((string) @file_get_contents('/proc/' . $pids[0] . '/cmdline'), '/bin/dns-lookup.php')) {
                    Swoole\Coroutine::sleep(0.1);
                    fwrite(STDOUT, $pids[0] . "\n");
                    fflush(STDOUT);
                    posix_kill(getmypid(), SIGKILL);
                }
                Swoole\Coroutine::sleep(0.005);
            } while (hrtime(true) / 1e9 < $until);
            exit(3);
        });
        (new NativeDnsResolver())->resolve('orphan.baander.app', hrtime(true) / 1e9 + 0.8);
    });
    exit(4);
}

$resolver = new NativeDnsResolver();
$both = $resolver->resolve('both.baander.app', hrtime(true) / 1e9 + 2);
check($both === ['8.8.8.8', '2001:4860:4860::8888'], 'Native lookup did not preserve both families.');
$nodata = $resolver->resolve('nodata.baander.app', hrtime(true) / 1e9 + 2);
check($nodata === ['8.8.8.8'], 'Definitive AAAA no-data must retain public A.');
try {
    $resolver->resolve('family-error.baander.app', hrtime(true) / 1e9 + 2);
    throw new LogicException('AAAA SERVFAIL must discard the successful A family.');
} catch (RuntimeException $failure) {
    check($failure->getMessage() === 'DNS lookup failed.', 'Unexpected native family failure.');
}
$many = $resolver->resolve('many.baander.app', hrtime(true) / 1e9 + 2);
check(count($many) === 101 && end($many) === '10.0.0.1', 'Native TCP fallback must retain the late private answer.');
$policy = new PublicDestinationPolicy();
$refused = [];
foreach (['private6.baander.app', 'many.baander.app', 'family-error.baander.app'] as $host) {
    try {
        $policy->resolve('https://' . $host . '/', [$host], [], hrtime(true) / 1e9 + 2);
        throw new LogicException('Unsafe complete answer was accepted: ' . $host);
    } catch (UnsafeDestinationException) {
        $refused[] = $host;
    }
}
$stalls = [];
foreach ([0, SWOOLE_HOOK_ALL] as $hooks) {
    $original = Swoole\Runtime::getHookFlags();
    Swoole\Runtime::setHookFlags($hooks);
    try {
        Swoole\Coroutine\run(static function () use (&$stalls, $hooks): void {
            $ticks = 0;
            $progress = new class {
                public bool $done = false;
            };
            $requests = 0;
            Swoole\Coroutine::create(static function () use (&$ticks, $progress): void {
                while (!$progress->done) {
                    ++$ticks;
                    Swoole\Coroutine::sleep(0.005);
                }
            });
            $transport = new MockHttpClient(static function () use (&$requests): MockResponse {
                ++$requests;
                return new MockResponse('{}');
            });
            $start = hrtime(true) / 1e9;
            try {
                (new PublicHttpClient($transport, new PublicDestinationPolicy()))->request(
                    'GET',
                    'https://stall.baander.app/',
                    ['stall.baander.app'],
                    ['max_duration' => 0.15],
                );
                throw new LogicException('Stalled DNS was accepted.');
            } catch (UnsafeDestinationException) {
                // Deadline failure is deliberately credential-safe at the policy boundary.
            } finally {
                $progress->done = true;
            }
            $elapsed = hrtime(true) / 1e9 - $start;
            check($ticks >= 5, 'DNS blocked the heartbeat coroutine.');
            check($requests === 0, 'HTTP transport started after DNS exhausted its budget.');
            check($elapsed < 0.8, 'DNS exceeded the bounded scheduling tolerance.');
            check(children() === [], 'Deadline cleanup left an owned child.');
            $stalls[] = [
                'hooks' => $hooks,
                'heartbeats' => $ticks,
                'http_calls' => $requests,
                'seconds' => round($elapsed, 4),
            ];
        });
    } finally {
        Swoole\Runtime::setHookFlags($original);
    }
}
$process = proc_open(
    [PHP_BINARY, __DIR__ . '/dns-orphan-supervisor.php', PHP_BINARY, __FILE__],
    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
);
check(is_resource($process), 'Cannot start orphan qualification supervisor.');
$output = stream_get_contents($pipes[1]);
$error = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
check(proc_close($process) === 0, 'Orphan qualification failed: ' . $error);
$orphan = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
check($orphan['reaped'] === true && $orphan['helper_signal'] === SIGALRM, 'Orphan helper was not alarm-bounded and reaped.');
echo json_encode([
    'families' => $both,
    'nodata' => $nodata,
    'many_answers' => count($many),
    'refused' => $refused,
    'stalls' => $stalls,
    'orphan' => $orphan,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
