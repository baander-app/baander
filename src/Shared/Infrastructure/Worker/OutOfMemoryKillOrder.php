<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\DeploymentUnit;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Out-of-memory scores of the worker units and their media work. Containers carry no memory
 * limit, so under host memory pressure the kernel picks victims by score: media work goes
 * before the execution unit's own processes, and the control unit's processes go last.
 * Raising a process's own score needs no privilege; lowering it does.
 */
#[Exclude]
final class OutOfMemoryKillOrder
{
    public const int CONTROL_UNIT = -500;
    public const int EXECUTION_UNIT = 300;
    public const int MEDIA_WORK = 600;

    public static function unit(DeploymentUnit $unit): int
    {
        return $unit === DeploymentUnit::Control ? self::CONTROL_UNIT : self::EXECUTION_UNIT;
    }

    /**
     * A rootless daemon cannot start a container below its own score, so the control unit's
     * negative score, and with it the kill order, would not apply.
     */
    public static function assertApplicable(mixed $securityOptions): void
    {
        if (!is_array($securityOptions) || !array_is_list($securityOptions)) {
            throw new \RuntimeException('Docker did not report its security options; the out-of-memory kill order cannot be verified.');
        }
        foreach ($securityOptions as $option) {
            if (!is_string($option)) {
                throw new \RuntimeException('Docker reported invalid security options; the out-of-memory kill order cannot be verified.');
            }
            if (in_array('name=rootless', explode(',', $option), true)) {
                throw new \RuntimeException('Rootless Docker cannot apply the negative out-of-memory score that protects the control worker; run the workers on a rootful Docker daemon.');
            }
        }
    }

    /** Raises the calling process's score to media work, before it allocates job memory. */
    public static function raiseToMediaWork(string $path = '/proc/self/oom_score_adj'): void
    {
        if (@file_put_contents($path, (string) self::MEDIA_WORK) === false
            || trim((string) @file_get_contents($path)) !== (string) self::MEDIA_WORK) {
            throw new \RuntimeException('Media work could not raise its out-of-memory score.');
        }
    }
}
