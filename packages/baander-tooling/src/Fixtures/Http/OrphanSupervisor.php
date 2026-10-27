<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Http;

use Baander\Tooling\Process\{ProcessRunner, ProcessSpec, SignalScope};
use FFI;
use RuntimeException;
use Swoole\Process;

final class OrphanSupervisor
{
    /** @return list<int> */
    private static function children(): array
    {
        $path = '/proc/self/task/' . getmypid() . '/children';
        $value = file_get_contents($path) |> trim(...);
        return $value === '' ? [] : array_map(intval(...), explode(' ', $value));
    }

    /** @return array{parent_signal:int,helper_signal:int,reaped:bool} */
    public static function run(string $php, string $subject): array
    {
        if (!extension_loaded('FFI')) {
            throw new RuntimeException('PHP extension FFI is required for the DNS orphan qualification');
        }
        $libc = FFI::cdef(<<<'C'
            int prctl(int option, unsigned long arg2, unsigned long arg3, unsigned long arg4, unsigned long arg5);
            int waitpid(int pid, int *status, int options);
            C);
        if ($libc->prctl(36, 1, 0, 0, 0) !== 0) {
            throw new RuntimeException('Cannot enable PR_SET_CHILD_SUBREAPER');
        }
        $previous = self::children();
        $status = $libc->new('int');
        $signals = new SignalScope();
        try {
            $parent = new ProcessRunner()->run(new ProcessSpec(
                argv: [$php, $subject, 'orphan-parent'],
                environment: getenv(),
                timeout: 4,
            ));
            if ($parent->signal !== 9) {
                throw new RuntimeException('Parent did not exit abruptly from SIGKILL: ' . $parent->exitCode . '; ' . $parent->stderr);
            }
            $pid = trim($parent->stdout);
            if (!ctype_digit($pid) || !in_array((int) $pid, self::children(), true)) {
                throw new RuntimeException('Parent did not identify an adopted DNS helper');
            }
            $helper = (int) $pid;
            $deadline = hrtime(true) / 1e9 + 4;
            do {
                $signals->dispatch();
                if ($signals->receivedSignal() !== null) {
                    throw new RuntimeException('DNS orphan qualification interrupted');
                }
                $reaped = $libc->waitpid($helper, FFI::addr($status), 1);
                if ($reaped === $helper) {
                    $rawStatus = unpack('istatus', FFI::string(FFI::addr($status), FFI::sizeof($status)))['status'];
                    $signal = $rawStatus & 0x7f;
                    if ($signal !== 14) {
                        throw new RuntimeException('Helper did not exit from its own alarm: ' . $rawStatus);
                    }
                    return [
                        'parent_signal' => 9,
                        'helper_signal' => 14,
                        'reaped' => true,
                    ];
                }
                if ($reaped === -1) {
                    throw new RuntimeException('Cannot reap adopted DNS helper');
                }
                usleep(10_000);
            } while (hrtime(true) / 1e9 < $deadline);
            throw new RuntimeException('Orphan helper outlived its finite alarm');
        } finally {
            foreach (array_diff(self::children(), $previous) as $child) {
                Process::kill($child, 9);
                $libc->waitpid($child, FFI::addr($status), 0);
            }
            $libc->prctl(36, 0, 0, 0, 0);
            $signals->close();
        }
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments): int
    {
        if (count($arguments) !== 2) {
            throw new \InvalidArgumentException('Usage: dns-orphan-supervisor.php PHP PARENT');
        }
        fwrite(STDOUT, json_encode(self::run(...$arguments), JSON_THROW_ON_ERROR) . "\n");
        return 0;
    }
}
