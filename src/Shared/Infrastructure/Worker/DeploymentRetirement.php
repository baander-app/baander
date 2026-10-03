<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Durable isolation-verified retirement intent; completion records trusted immutable-ID absence. */
#[Exclude]
final readonly class DeploymentRetirement
{
    public function __construct(public DeploymentContainer $binding, public bool $completed)
    {
    }
}
