<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Closure;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Trusted external recovery only; it does not implement Docker verification. */
#[Exclude]
final class DeploymentContainmentController
{
    /**
     * Retirement must verify the exact immutable container ID and deployment/boot
     * binding, then successfully remove the whole deployment containment boundary.
     * Stopped inspection alone is insufficient. Throw on any uncertain outcome.
     * @param Closure(string, DeploymentLease): void $retireContainer
     */
    public function __construct(private readonly DoctrineDeploymentLease $leases, private readonly Closure $retireContainer)
    {
    }

    public function recover(string $namespace, string $expectedBootId, string $fullContainerId): bool
    {
        DeploymentLease::validateIdentity($namespace, $expectedBootId);
        if (!preg_match('/\A[0-9a-f]{64}\z/D', $fullContainerId)) {
            throw new \InvalidArgumentException('Recovery requires a full immutable Docker container ID.');
        }
        $lease = $this->leases->findForContainment($namespace);
        if ($lease === null || $lease->bootId !== $expectedBootId) {
            return false;
        }
        // The read is already committed. No database transaction spans Docker
        // work, and a concurrent recovery cannot make this old token release a
        // newer owner or epoch.
        ($this->retireContainer)($fullContainerId, $lease);
        return $this->leases->acknowledgeContainment($lease);
    }
}
