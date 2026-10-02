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
        private DoctrineDeploymentLease $leases,
        private Closure $dockerExecutor,
    ) {
    }

    public function recover(string $namespace, string $expectedBootId): bool
    {
        DeploymentLease::validateIdentity($namespace, $expectedBootId);
        $binding = $this->inventory->find($namespace, $expectedBootId);
        if ($binding === null) {
            return false;
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
        $controller = new DeploymentContainmentController($this->leases, $containment->retire(...));
        return $controller->recover($namespace, $expectedBootId, $binding->containerId);
    }
}
