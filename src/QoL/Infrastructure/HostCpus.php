<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure;

/**
 * The number of online CPUs on the host. Media containers run without CPU limits, so the
 * media child sees the same CPUs as the execution container that runs its encoder.
 */
final class HostCpus
{
    private ?int $count;

    public function __construct(?int $count = null)
    {
        if ($count !== null && $count < 1) {
            throw new \InvalidArgumentException('A host has at least one CPU.');
        }
        $this->count = $count;
    }

    public function count(): int
    {
        if ($this->count === null) {
            $online = posix_sysconf(POSIX_SC_NPROCESSORS_ONLN);
            $this->count = $online > 0 ? $online : 1;
        }

        return $this->count;
    }
}
