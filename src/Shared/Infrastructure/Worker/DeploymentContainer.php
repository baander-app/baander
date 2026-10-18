<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\DeploymentUnit;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Immutable pre-start binding; registration alone grants no lease or containment authority. */
#[Exclude]
final readonly class DeploymentContainer
{
    public function __construct(
        public string $namespace,
        public string $bootId,
        public string $daemonId,
        public string $containerId,
        public DeploymentUnit $unit,
    ) {
        DeploymentLease::validateIdentity($namespace, $bootId);
        if (!preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D', $daemonId) || !preg_match('/\A[0-9a-f]{64}\z/D', $containerId)) {
            throw new \InvalidArgumentException('Deployment inventory requires a bounded safe daemon identity and full immutable container ID.');
        }
    }

    public function matches(self $other): bool
    {
        return $this->namespace === $other->namespace && $this->bootId === $other->bootId
            && $this->daemonId === $other->daemonId && $this->containerId === $other->containerId
            && $this->unit === $other->unit;
    }
}
