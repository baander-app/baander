<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole\Drain;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Each scenario runs in its own PHP process, which plays the master: it starts victims and a
 * watchdog, whose stderr line the test reads.
 */
final class DrainWatchdogTest extends TestCase
{
    private const string SCENARIO = <<<'PHP'
        require $argv[1];
        use App\Shared\Infrastructure\Swoole\Drain\DrainWatchdog;
        function check(bool $condition, string $message): void {
            if (!$condition) { throw new RuntimeException($message); }
        }
        function alive(int $pid): bool {
            $stat = @file_get_contents('/proc/' . $pid . '/stat');
            return $stat !== false && substr($stat, strrpos($stat, ')') + 2, 1) !== 'Z';
        }
        function child(array $command): array {
            $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => STDERR], $pipes);
            check(is_resource($process), 'Cannot start ' . implode(' ', $command));
            return [$process, proc_get_status($process)['pid']];
        }
        // Descriptors the watchdog must not inherit: a listening TCP socket and a unix socket pair.
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        check($listener !== false && $pair !== false, 'Cannot open the sockets');
        [$plainProcess, $plain] = child(['sleep', '60']);
        // setsid makes the shell a process group leader; its background sleep joins the group.
        [$groupProcess, $leader] = child(['setsid', 'sh', '-c', 'sleep 60 & exec sleep 61']);
        $member = null;
        for ($i = 0; $i < 100 && $member === null; $i++) {
            $children = trim((string) @file_get_contents("/proc/$leader/task/$leader/children"));
            $member = $children === '' ? null : (int) explode(' ', $children)[0];
            usleep(10_000);
        }
        check($member !== null && posix_getpgid($member) === $leader, 'The group has no member');
        $deadlineSeconds = (float) $argv[3];
        $startedAt = hrtime(true);
        $watchdog = DrainWatchdog::start(
            [PHP_BINARY, $argv[2], $argv[1], 'app:server:drain:watch'],
            $startedAt + (int) ($deadlineSeconds * 1e9),
            [$plain],
            [$leader],
        );
        $running = false;
        for ($i = 0; $i < 500 && !$running; $i++) {
            $running = str_contains((string) @file_get_contents('/proc/' . $watchdog->pid . '/cmdline'), 'app:server:drain:watch');
            usleep(10_000);
        }
        check($running, 'The watchdog command did not start');
        $sockets = [];
        foreach (glob('/proc/' . $watchdog->pid . '/fd/*') ?: [] as $link) {
            $target = (string) @readlink($link);
            if (str_starts_with($target, 'socket:')) { $sockets[] = basename($link) . '=' . $target; }
        }
        if ($argv[4] === 'early') {
            usleep(300_000);
            $fired = $watchdog->end();
        } else {
            while (alive($plain) && hrtime(true) - $startedAt < ($deadlineSeconds + 5) * 1e9) { usleep(20_000); }
            usleep(200_000);
            $fired = $watchdog->end();
        }
        $result = [
            'fired' => $fired,
            'endedAfter' => (hrtime(true) - $startedAt) / 1e9,
            'sockets' => $sockets,
            'plainAlive' => alive($plain),
            'leaderAlive' => alive($leader),
            'memberAlive' => alive($member),
        ];
        foreach ([$plain, $leader, $member] as $pid) { posix_kill($pid, SIGKILL); }
        proc_close($plainProcess);
        proc_close($groupProcess);
        echo json_encode($result), "\n";
        PHP;

    public function testAtTheDeadlineItKillsTheProcessesAndGroups(): void
    {
        [$result, $errors] = $this->scenario(deadlineSeconds: 1.5, mode: 'fire');

        self::assertTrue($result['fired']);
        self::assertGreaterThanOrEqual(1.5, $result['endedAfter']);
        self::assertFalse($result['plainAlive'], 'the process was not killed');
        self::assertFalse($result['leaderAlive'], 'the group leader was not killed');
        self::assertFalse($result['memberAlive'], 'the group member was not killed');
        self::assertSame([], $result['sockets'], 'the watchdog holds sockets of the process that started it');
        self::assertMatchesRegularExpression(
            '/^The web server did not stop by its drain deadline: killed processes \[\d+\] and process groups \[\d+\]\.$/m',
            $errors,
        );
    }

    public function testEndedBeforeTheDeadlineItKillsNothing(): void
    {
        [$result, $errors] = $this->scenario(deadlineSeconds: 30, mode: 'early');

        self::assertFalse($result['fired']);
        self::assertLessThan(5, $result['endedAfter']);
        self::assertTrue($result['plainAlive']);
        self::assertTrue($result['leaderAlive']);
        self::assertTrue($result['memberAlive']);
        self::assertSame([], $result['sockets'], 'the watchdog holds sockets of the process that started it');
        self::assertSame('', $errors);
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function scenario(float $deadlineSeconds, string $mode): array
    {
        $root = dirname(__DIR__, 6);
        $process = new Process([
            PHP_BINARY, '-r', self::SCENARIO,
            $root . '/vendor/autoload.php',
            $root . '/tests/Fixtures/WebShutdown/drain-watchdog-console.php',
            (string) $deadlineSeconds,
            $mode,
        ]);
        $process->setTimeout(20);
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());

        return [json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR), $process->getErrorOutput()];
    }
}
