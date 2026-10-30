<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use RuntimeException;

final class AutoscalingStatusObservation
{
    public const int INTERVAL_MICROSECONDS = 250_000;

    /** @param callable(float):array<string,mixed> $snapshot
     * @return array{status:array<string,mixed>,attempts:int}
     */
    public static function capture(callable $snapshot): array
    {
        $deadline = hrtime(true) + self::INTERVAL_MICROSECONDS * 1000;
        $attempts = 0;
        while (true) {
            try {
                ++$attempts;
                return ['status' => $snapshot(hrtime(true) / 1e9), 'attempts' => $attempts];
            } catch (RuntimeException $error) {
                // Reserve the admission reader's 50ms lock deadline before another real read.
                if ($error->getMessage() !== 'Admission lock deadline exceeded.' || hrtime(true) + 50_000_000 >= $deadline) {
                    throw $error;
                }
                usleep(1000);
            }
        }
    }
}
