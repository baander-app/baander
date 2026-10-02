<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Closure;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Throwable;

/** Nonblocking management of a fixed admitted set; it never executes job handlers. */
#[Exclude]
final class WorkerSupervisor
{
    /** @var array<string, array{definition: WorkerDefinition, generation: int, identity: ?WorkerLaunchIdentity, containmentConfirmed: bool, plannedState: ?string, child: ?WorkerChildProcess, state: string, restartAt: ?float, exitCode: ?int, terminationSignal: ?int, errorCode: ?string}> */
    private array $workers = [];

    private readonly string $supervisorBootId;
    private bool $draining = false;
    private ?float $lastTime = null;

    /**
     * Admission uses declared reservations only. Deployment containment must enforce actual resources and own descendants.
     *
     * @param list<WorkerDefinition> $definitions
     * @param Closure(WorkerDefinition, WorkerLaunchIdentity): WorkerChildProcess $launcher
     */
    public function __construct(
        array $definitions,
        int $maxChildren,
        int $memoryLimitBytes,
        int $supervisorReservationBytes,
        private readonly Closure $launcher,
        private readonly string $deploymentId,
        ?string $supervisorBootId = null,
    ) {
        $this->supervisorBootId = $supervisorBootId ?? bin2hex(random_bytes(16));
        if ($definitions === [] || $maxChildren < 1 || count($definitions) > $maxChildren
            || $memoryLimitBytes < 1 || $supervisorReservationBytes < 1 || $supervisorReservationBytes > $memoryLimitBytes
        ) {
            throw new InvalidArgumentException('Worker set and supervisor reservations must fit positive admission ceilings.');
        }
        $reserved = $supervisorReservationBytes;
        $policies = [];
        foreach ($definitions as $definition) {
            new WorkerLaunchIdentity($deploymentId, $this->supervisorBootId, $definition->id, 1);
            $policyId = spl_object_id($definition->restartPolicy);
            if (isset($this->workers[$definition->id]) || isset($policies[$policyId])) {
                throw new InvalidArgumentException('Worker IDs and restart policy instances must be unique.');
            }
            if ($definition->memoryReservationBytes > $memoryLimitBytes - $reserved) {
                throw new InvalidArgumentException('Worker memory reservations exceed the admission ceiling.');
            }
            $reserved += $definition->memoryReservationBytes;
            $policies[$policyId] = true;
            $this->workers[$definition->id] = [
                'definition' => $definition,
                'generation' => 0,
                'identity' => null,
                'containmentConfirmed' => true,
                'plannedState' => null,
                'child' => null,
                'state' => 'starting',
                'restartAt' => null,
                'exitCode' => null,
                'terminationSignal' => null,
                'errorCode' => null,
            ];
        }
    }

    /** Losing authority permanently closes admission; later ticks still reap and enforce drain deadlines. */
    public function tick(float $now, bool $hasAuthority): void
    {
        $this->checkTime($now);
        if (!$hasAuthority) {
            $this->draining = true;
        }
        $firstError = null;
        foreach ($this->workers as &$worker) {
            $child = $worker['child'];
            if ($child !== null) {
                try {
                    if ($child->poll($now)) {
                        $worker['state'] = $this->draining ? 'draining' : 'running';
                    } else {
                        $worker['exitCode'] = $child->exitCode();
                        $worker['terminationSignal'] = $child->terminationSignal();
                        $worker['child'] = null;
                        if ($this->draining) {
                            $worker['plannedState'] = $worker['containmentConfirmed'] ? null : 'stopped';
                            $worker['state'] = $worker['containmentConfirmed'] ? 'stopped' : 'awaiting_containment';
                        } else {
                            try {
                                $this->scheduleRestart($worker, $now);
                            } catch (Throwable $error) {
                                $worker['errorCode'] = 'restart_policy_failed';
                                $this->draining = true;
                                $firstError ??= $error;
                            }
                        }
                    }
                } catch (Throwable $error) {
                    $worker['errorCode'] = 'poll_failed';
                    $this->draining = true;
                    $firstError ??= $error;
                }
                continue;
            }
            if ($this->draining) {
                $worker['plannedState'] = $worker['containmentConfirmed'] ? null : 'stopped';
                $worker['state'] = $worker['containmentConfirmed'] ? 'stopped' : 'awaiting_containment';
                $worker['restartAt'] = null;
                continue;
            }
            if (!$worker['containmentConfirmed']) {
                continue;
            }
            if ($worker['state'] === 'exhausted' || ($worker['restartAt'] !== null && $now < $worker['restartAt'])) {
                continue;
            }
            try {
                ++$worker['generation'];
                $worker['identity'] = new WorkerLaunchIdentity($this->deploymentId, $this->supervisorBootId, $worker['definition']->id, $worker['generation']);
                $worker['containmentConfirmed'] = false;
                $worker['plannedState'] = null;
                $worker['child'] = ($this->launcher)($worker['definition'], $worker['identity']);
                $worker['state'] = 'starting';
                $worker['restartAt'] = null;
                $worker['errorCode'] = null;
            } catch (Throwable) {
                $worker['errorCode'] = 'launch_failed';
                try {
                    $this->scheduleRestart($worker, $now);
                } catch (Throwable $error) {
                    $worker['errorCode'] = 'restart_policy_failed';
                    $this->draining = true;
                    $firstError ??= $error;
                }
            }
        }
        unset($worker);
        if ($this->draining) {
            $stopError = $this->stopChildren($now);
            $firstError ??= $stopError;
        }
        if ($firstError !== null) {
            throw $firstError;
        }
    }

    /** Signal every live child in this call; one failing signal must not prevent sibling shutdown. */
    public function requestDrain(float $now): void
    {
        $this->checkTime($now);
        $this->draining = true;
        $error = $this->stopChildren($now);
        if ($error !== null) {
            throw $error;
        }
    }

    /**
     * Require current PID and full launch identity from externally verified heartbeat evidence.
     * A live child or a PID reused by a later launch never supplies readiness by itself.
     *
     * @param array<string, mixed> $healthyWorkers Values contain a pid and the four-field identity array.
     */
    public function isReady(array $healthyWorkers): bool
    {
        if ($this->draining) {
            return false;
        }
        foreach ($this->workers as $id => $worker) {
            $evidence = $healthyWorkers[$id] ?? null;
            if ($worker['state'] !== 'running' || $worker['child'] === null || $worker['identity'] === null
                || !is_array($evidence) || ($evidence['pid'] ?? null) !== $worker['child']->pid()
                || !is_array($evidence['identity'] ?? null) || !$worker['identity']->matches($evidence['identity'])
            ) {
                return false;
            }
        }

        return true;
    }

    /** Apply external containment proof only to the current, directly reaped launch attempt. */
    public function acknowledgeContainment(WorkerLaunchIdentity $identity): bool
    {
        if (!isset($this->workers[$identity->workerId])) {
            return false;
        }
        $worker = &$this->workers[$identity->workerId];
        if ($worker['child'] !== null || $worker['identity'] === null || $worker['containmentConfirmed'] || $worker['plannedState'] === null
            || !$worker['identity']->matches($identity->toArray())
        ) {
            return false;
        }
        $worker['containmentConfirmed'] = true;
        $worker['state'] = $this->draining ? 'stopped' : $worker['plannedState'];
        $worker['plannedState'] = null;

        return true;
    }

    /** Direct-child reap alone does not prove that descendants have been contained. */
    public function areDirectChildrenReaped(): bool
    {
        if (!$this->draining) {
            return false;
        }
        foreach ($this->workers as $worker) {
            if ($worker['child'] !== null) {
                return false;
            }
        }

        return true;
    }

    public function isStopped(): bool
    {
        if (!$this->areDirectChildrenReaped()) {
            return false;
        }
        foreach ($this->workers as $worker) {
            if (!$worker['containmentConfirmed']) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, array{state: string, pid: ?int, restartAt: ?float, exitCode: ?int, terminationSignal: ?int, errorCode: ?string, identity: ?array{deploymentId: string, supervisorBootId: string, workerId: string, generation: int}, containmentPending: bool, plannedState: ?string}> */
    public function snapshot(): array
    {
        $snapshot = [];
        foreach ($this->workers as $id => $worker) {
            $snapshot[$id] = [
                'state' => $worker['state'],
                'pid' => $worker['child']?->pid(),
                'restartAt' => $worker['restartAt'],
                'exitCode' => $worker['exitCode'],
                'terminationSignal' => $worker['terminationSignal'],
                'errorCode' => $worker['errorCode'],
                'identity' => $worker['identity']?->toArray(),
                'containmentPending' => !$worker['containmentConfirmed'],
                'plannedState' => $worker['plannedState'],
            ];
        }

        return $snapshot;
    }

    /** @param array{definition: WorkerDefinition, generation: int, identity: ?WorkerLaunchIdentity, containmentConfirmed: bool, plannedState: ?string, child: ?WorkerChildProcess, state: string, restartAt: ?float, exitCode: ?int, terminationSignal: ?int, errorCode: ?string} $worker */
    private function scheduleRestart(array &$worker, float $now): void
    {
        $delay = $worker['definition']->restartPolicy->nextDelay($now);
        $worker['restartAt'] = $delay === null ? null : $now + $delay;
        if ($worker['restartAt'] !== null && !is_finite($worker['restartAt'])) {
            throw new InvalidArgumentException('Restart deadline must be finite.');
        }
        $worker['plannedState'] = $delay === null ? 'exhausted' : 'backoff';
        $worker['state'] = $worker['containmentConfirmed'] ? $worker['plannedState'] : 'awaiting_containment';
        if ($worker['containmentConfirmed']) {
            $worker['plannedState'] = null;
        }
    }

    private function stopChildren(float $now): ?Throwable
    {
        $firstError = null;
        foreach ($this->workers as &$worker) {
            $worker['restartAt'] = null;
            if ($worker['child'] === null) {
                $worker['plannedState'] = $worker['containmentConfirmed'] ? null : 'stopped';
                $worker['state'] = $worker['containmentConfirmed'] ? 'stopped' : 'awaiting_containment';
                continue;
            }
            $worker['state'] = 'draining';
            $worker['plannedState'] = 'stopped';
            try {
                $worker['child']->requestStop($now, $worker['definition']->stopGraceSeconds);
                if (!$worker['child']->poll($now)) {
                    $worker['exitCode'] = $worker['child']->exitCode();
                    $worker['terminationSignal'] = $worker['child']->terminationSignal();
                    $worker['child'] = null;
                    $worker['state'] = 'awaiting_containment';
                }
            } catch (Throwable $error) {
                $worker['errorCode'] = 'stop_failed';
                $firstError ??= $error;
            }
        }
        unset($worker);

        return $firstError;
    }

    private function checkTime(float $now): void
    {
        if (!is_finite($now) || $now < 0 || ($this->lastTime !== null && $now < $this->lastTime)) {
            throw new InvalidArgumentException('Worker supervisor requires a finite, non-decreasing monotonic time.');
        }
        $this->lastTime = $now;
    }
}
