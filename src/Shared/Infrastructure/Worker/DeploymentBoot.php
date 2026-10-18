<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** A complete fixed-pair snapshot; observing it grants no create or start permission. */
#[Exclude]
final readonly class DeploymentBoot
{
    public function __construct(
        public string $namespace,
        public string $bootId,
        public ?string $manifestHash,
        public string $daemonId,
        public DeploymentUnitState $control,
        public DeploymentUnitState $execution,
        public bool $retirementRequested,
        public bool $retired,
        public bool $importedSingleton,
    ) {
    }
}
