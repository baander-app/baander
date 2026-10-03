<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Closure;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Trusted external recovery using committed inventory, never a caller-selected container. */
#[Exclude]
final readonly class RegisteredDeploymentRecovery
{
    /**
     * The executor must use a fixed trusted Docker endpoint and bounded I/O.
     * @param Closure(list<string>): string $dockerExecutor Successful commands return stdout; failures throw.
     */
    public function __construct(
        private DoctrineDeploymentInventory $inventory,
        private DoctrineDeploymentRetirement $retirements,
        private Closure $dockerExecutor,
    ) {
    }

    /**
     * Mutates Docker and durable ownership state; repeated calls re-observe committed outcomes.
     * @phpstan-impure
     */
    public function recover(string $namespace, string $expectedBootId): bool
    {
        DeploymentLease::validateIdentity($namespace, $expectedBootId);
        $binding = $this->inventory->find($namespace, $expectedBootId);
        if ($binding === null) {
            return false;
        }
        $retirement = $this->retirements->find($namespace, $expectedBootId);
        if ($retirement !== null && ($retirement->binding->namespace !== $binding->namespace
            || $retirement->binding->bootId !== $binding->bootId
            || $retirement->binding->daemonId !== $binding->daemonId
            || $retirement->binding->containerId !== $binding->containerId)) {
            throw new \RuntimeException('Retirement intent does not match immutable deployment inventory.');
        }
        if ($retirement?->completed === true) {
            return true;
        }
        $containment = new DockerWorkerContainment(function (array $arguments) use ($binding): string {
            // Recheck before both inspection and removal; a daemon change after
            // inspection must not turn a different daemon into containment proof.
            $daemonId = ($this->dockerExecutor)(['info', '--format', '{{.ID}}']);
            if (strlen($daemonId) > 256 || trim($daemonId) !== $binding->daemonId) {
                throw new \RuntimeException('Docker daemon does not match the registered deployment.');
            }
            return ($this->dockerExecutor)($arguments);
        });
        if ($retirement === null) {
            // Absence without a committed, previously verified binding supplies
            // no containment evidence. Never interpret an inspect error as absence.
            $containment->verifyIsolation($binding->containerId, $namespace, $expectedBootId);
            if (!$this->retirements->begin($binding)) {
                return false;
            }
        }
        // The intent blocks further admission for this boot before any removal.
        // A lost removal reply can now be reconciled by exact-ID absence on the
        // same daemon. Completion and own-boot lease release commit together.
        $containment->reconcileRemoval($binding->containerId, $namespace, $expectedBootId);
        return $this->retirements->complete($binding);
    }
}
