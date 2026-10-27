<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Baander\RegistryTests;

final class BackupCadenceContract
{
    public static function run(string $python, string $coordinator): void
    {
        $fixture = new BackupFixture($python, $coordinator);
        $fixture->config['attempt_timeout_seconds'] = 240;
        $start = hrtime(true) / 1e9;
        $worker = new Process($fixture->arguments('run'), $fixture->root . '/cadence.log', $fixture->environment('cadence'));
        $stopped = false;
        try {
            self::until(
                static fn(): bool => is_file($fixture->root . '/first-capture') && count($fixture->calls()) >= 2,
                $start + 10,
                'Immediate capture did not start.',
            );
            Runtime::requireThat(hrtime(true) / 1e9 - $start < 10, 'First capture was not immediate.');
            $worker->signal(19);
            $stopped = true;
            echo "Observed immediate capture; coordinator suspended until the missed tick.\n";
            self::until(static fn(): bool => hrtime(true) / 1e9 >= $start + 360, $start + 365, 'Failed to reach resume time.');
            $worker->signal(18);
            $stopped = false;
            self::until(
                static fn(): bool => $fixture->status()['latest_attempt']['outcome'] === 'failed',
                $start + 380,
                'Resumed capture did not fail its original deadline.',
            );
            $status = $fixture->status();
            Runtime::requireThat($status['latest_attempt']['error'] === 'attempt deadline exceeded', 'Resumed capture reported a different failure.');
            self::until(
                static fn(): bool => file_get_contents($fixture->root . '/cadence.log')
                    |> (static fn(string $log): bool => str_contains($log, 'missed 1 backup schedule tick(s)')),
                $start + 380,
                'Missed tick was not reported.',
            );
            $fixture->assertClean();
            Runtime::requireThat(!isset($status['last_success']), 'Expired capture published success.');
            echo "Observed deadline failure and one missed tick; waiting for original 600-second tick.\n";
            self::until(static function () use ($fixture, $start): bool {
                Runtime::requireThat(self::captureCount($fixture) === 1, 'Coordinator attempted a catch-up backup before its scheduled tick.');
                return hrtime(true) / 1e9 >= $start + 575;
            }, $start + 580, 'Failed to observe the quiet interval.');
            self::until(static fn(): bool => self::captureCount($fixture) === 2, $start + 625, 'Next attempt did not use the original 600-second tick.');
            $elapsed = hrtime(true) / 1e9 - $start;
            Runtime::requireThat($elapsed >= 580 && $elapsed <= 625, 'Next capture drifted away from the original tick.');
            self::until(
                static fn(): bool => $fixture->status()['latest_attempt']['outcome'] === 'success',
                $start + 640,
                'Scheduled capture did not complete.',
            );
            self::until(static function () use ($fixture, $start): bool {
                Runtime::requireThat(self::captureCount($fixture) === 2, 'Coordinator emitted a catch-up burst.');
                return hrtime(true) / 1e9 >= $start + 630;
            }, $start + 645, 'Failed to observe post-capture quiet interval.');
            $worker->signal(15);
            Runtime::requireThat($worker->wait(5) === 130, 'Recurring backup did not shut down.');
            $fixture->assertClean();
            Runtime::requireThat(hrtime(true) / 1e9 - $start < 900, 'Cadence contract exceeded its bound.');
            echo "PASS: immediate capture, real deadline, missed tick, original cadence and no catch-up burst\n";
        } finally {
            if ($stopped) {
                $worker->signal(18);
            }
            try {
                $worker->close(false);
            } finally {
                // External commands have their own sessions and also need
                // cleanup if the coordinator could not finish shutdown.
                $fixture->close();
            }
        }
    }

    private static function captureCount(BackupFixture $fixture): int
    {
        return $fixture->calls()
            |> (static fn(array $calls): array => array_filter($calls, static fn(array $call): bool => $call[0] === 'curl'))
            |> count(...);
    }

    private static function until(callable $condition, float $deadline, string $message): void
    {
        while (!$condition()) {
            Runtime::requireThat(hrtime(true) / 1e9 < $deadline, $message);
            usleep(100000);
        }
    }
}
