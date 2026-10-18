<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\DeploymentUnit;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Durable unit state, including unresolved claims and trusted retirement evidence. */
#[Exclude]
final readonly class DeploymentUnitState
{
    public function __construct(
        public DeploymentUnit $unit,
        public ?string $recipeHash,
        public bool $createClaimed,
        public bool $cancelled,
        public ?DeploymentContainer $binding,
        public bool $startClaimed,
        public ?DeploymentRetirement $retirement,
    ) {
    }
}
