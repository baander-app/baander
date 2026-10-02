<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Identifies one launch attempt independently of operating-system PID reuse. */
#[Exclude]
final readonly class WorkerLaunchIdentity
{
    public function __construct(
        public string $deploymentId,
        public string $supervisorBootId,
        public string $workerId,
        public int $generation,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $deploymentId) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $supervisorBootId) !== 1
            || preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $workerId) !== 1
            || $generation < 1
        ) {
            throw new InvalidArgumentException('Worker launch identity requires a safe deployment namespace, a lowercase 32-hex boot token, a worker ID and a positive generation.');
        }
    }

    /** @return array{deploymentId: string, supervisorBootId: string, workerId: string, generation: int} */
    public function toArray(): array
    {
        return [
            'deploymentId' => $this->deploymentId,
            'supervisorBootId' => $this->supervisorBootId,
            'workerId' => $this->workerId,
            'generation' => $this->generation,
        ];
    }

    /**
     * Compare fields without depending on serialized object key order.
     *
     * @param array<array-key, mixed> $evidence
     */
    public function matches(array $evidence): bool
    {
        return ($evidence['deploymentId'] ?? null) === $this->deploymentId
            && ($evidence['supervisorBootId'] ?? null) === $this->supervisorBootId
            && ($evidence['workerId'] ?? null) === $this->workerId
            && ($evidence['generation'] ?? null) === $this->generation;
    }
}
