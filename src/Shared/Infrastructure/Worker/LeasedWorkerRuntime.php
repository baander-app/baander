<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Closure;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Coordinates asynchronous lease operations and child management without database I/O in its loop. */
#[Exclude]
final class LeasedWorkerRuntime
{
    private readonly WorkerSupervisor $supervisor;
    /** @var Closure(): float */
    private readonly Closure $clock;
    private ?LeaseAgentProcess $agent = null;
    private bool $draining = false;
    private ?string $failure = null;
    private float $lastTime = 0.0;

    /**
     * The process ceiling reserves one slot for the lease helper. Management memory
     * includes both the supervisor and helper; deployment containment enforces actual usage.
     *
     * @param list<WorkerDefinition> $definitions
     * @param Closure(WorkerDefinition, WorkerLaunchIdentity): WorkerChildProcess $workerLauncher
     * @param Closure(array{action: 'acquire'|'renew', sequence: int, namespace: string, bootId: string, epoch: ?int, ttlSeconds: int}, float): LeaseAgentProcess $leaseLauncher
     * @param (Closure(): float)|null $clock
     */
    public function __construct(
        array $definitions,
        int $maxChildProcesses,
        int $memoryLimitBytes,
        int $managementReservationBytes,
        private readonly LeaseAuthority $authority,
        Closure $workerLauncher,
        private readonly Closure $leaseLauncher,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->supervisor = new WorkerSupervisor($definitions, $maxChildProcesses - 1, $memoryLimitBytes, $managementReservationBytes,
            function (WorkerDefinition $definition, WorkerLaunchIdentity $identity) use ($workerLauncher): WorkerChildProcess {
                // Process creation for earlier siblings may consume time. Check each
                // admission against a fresh clock, not just the tick's initial snapshot.
                if (!$this->authority->hasAuthority($this->now())) {
                    throw new \RuntimeException('Worker admission authority has expired.');
                }
                return $workerLauncher($definition, $identity);
            }, $authority->namespace, $authority->bootId);
    }

    public function tick(): void
    {
        try {
            if ($this->agent !== null && !$this->agent->poll($this->now())) {
                $reply = $this->agent->takeResult();
                $this->agent = null;
                if (!$this->draining) {
                    $grant = $reply !== null && $reply['success'] && $reply['lease'] !== null
                        ? new DeploymentLease($reply['lease']['namespace'], $reply['lease']['bootId'], $reply['lease']['epoch']) : null;
                    if ($reply === null || !$this->authority->accept($reply['sequence'], $grant, $this->now())) {
                        $this->failure = 'lease_reply_rejected';
                        $this->authority->revoke($this->now());
                    }
                }
            }
            if ($this->draining || $this->authority->isRevoked($this->now())) {
                if (!$this->draining) {
                    $this->failure ??= 'authority_lost';
                }
                $this->requestDrain();
                return;
            }
            // Pending initial acquisition must not irreversibly drain an unstarted core.
            if ($this->authority->hasAuthority($this->now())) {
                $this->supervisor->tick($this->now(), true);
            }
            if ($this->authority->isRevoked($this->now())) {
                $this->failure ??= 'authority_lost';
                $this->requestDrain();
                return;
            }
            if ($this->agent === null && ($request = $this->authority->request($this->now())) !== null) {
                $this->agent = ($this->leaseLauncher)($request, $this->now());
            }
        } catch (\Throwable $error) {
            $this->failure ??= 'management_failed';
            try {
                $this->requestDrain();
            } catch (\Throwable) {
                // Keep the original error; later ticks retain and reap owned processes.
            }
            throw $error;
        }
    }

    /** Cancel lease work and signal all children; no containment acknowledgment or lease release occurs here. */
    public function requestDrain(): void
    {
        $this->draining = true;
        $firstError = null;
        try {
            $now = $this->now();
        } catch (\Throwable $error) {
            // A broken clock must not prevent the first stop signals. The owner
            // still receives the error and must exit through its containment boundary.
            $now = $this->lastTime;
            $firstError = $error;
        }
        try {
            $this->authority->revoke($now);
        } catch (\Throwable $error) {
            $firstError ??= $error;
        }
        try {
            $this->agent?->cancel($now);
        } catch (\Throwable $error) {
            $firstError ??= $error;
        }
        try {
            $this->supervisor->requestDrain($now);
        } catch (\Throwable $error) {
            $firstError ??= $error;
        }
        if ($firstError !== null) {
            throw $firstError;
        }
    }

    /** @param array<string, mixed> $healthyWorkers */
    public function isReady(array $healthyWorkers): bool
    {
        return !$this->draining && $this->authority->hasAuthority($this->now()) && $this->supervisor->isReady($healthyWorkers);
    }

    public function acknowledgeContainment(WorkerLaunchIdentity $identity): bool
    {
        return $this->supervisor->acknowledgeContainment($identity);
    }

    public function isStopped(): bool
    {
        return $this->agent === null && $this->supervisor->isStopped();
    }

    /** Allows the outer containment boundary to exit; does not imply descendants are gone. */
    public function areDirectChildrenReaped(): bool
    {
        return $this->agent === null && $this->supervisor->areDirectChildrenReaped();
    }

    public function failureCode(): ?string
    {
        return $this->failure;
    }

    /** @return array<string, array<string, mixed>> */
    public function snapshot(): array
    {
        return $this->supervisor->snapshot();
    }

    /**
     * Reads a changing clock and advances the last observed timestamp.
     * @phpstan-impure
     */
    private function now(): float
    {
        try {
            $now = ($this->clock)();
            if (!is_finite($now) || $now < $this->lastTime) {
                throw new \InvalidArgumentException('Worker runtime requires a finite, non-decreasing monotonic clock.');
            }
        } catch (\Throwable $error) {
            $this->authority->revoke($this->lastTime);
            throw $error;
        }
        return $this->lastTime = $now;
    }
}
