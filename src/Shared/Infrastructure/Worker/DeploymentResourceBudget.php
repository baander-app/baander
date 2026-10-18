<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Container-wide ceilings include every process and thread descended from PID 1. */
#[Exclude]
final readonly class DeploymentResourceBudget
{
    /** @var list<int> */
    public array $cpuSet;

    /** @param array<array-key,mixed> $cpuSet */
    public function __construct(public int $memoryBytes, public int $nanoCpus, public int $pidsLimit, array $cpuSet)
    {
        if ($memoryBytes < 16 * 1024 * 1024 || $memoryBytes > 1024 * 1024 * 1024 * 1024
            || $nanoCpus < 1_000_000 || $nanoCpus > 64_000_000_000 || $pidsLimit < 8 || $pidsLimit > 4096
            || !array_is_list($cpuSet) || $cpuSet === [] || count($cpuSet) > 64) {
            throw new \InvalidArgumentException('Worker resources require bounded memory, CPU, PIDs and a nonempty CPU set.');
        }
        $validated = [];
        foreach ($cpuSet as $cpu) {
            if (!is_int($cpu) || $cpu < 0 || $cpu > 4095 || in_array($cpu, $validated, true)) {
                throw new \InvalidArgumentException('Worker CPU sets require distinct integer CPU identifiers.');
            }
            $validated[] = $cpu;
        }
        if ($nanoCpus > count($validated) * 1_000_000_000) {
            throw new \InvalidArgumentException('Worker CPU ceiling cannot exceed its CPU set.');
        }
        sort($validated, SORT_NUMERIC);
        $this->cpuSet = $validated;
    }
}
