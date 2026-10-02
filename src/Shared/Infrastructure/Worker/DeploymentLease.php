<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Committed deployment ownership token; not evidence of process containment. */
#[Exclude]
final readonly class DeploymentLease
{
    public function __construct(public string $namespace, public string $bootId, public int $epoch)
    {
        self::validateIdentity($namespace, $bootId);
        if ($epoch < 1) {
            throw new \InvalidArgumentException('Deployment lease epoch must be positive.');
        }
    }

    public static function validateIdentity(string $namespace, string $bootId): void
    {
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D', $namespace)
            || !preg_match('/\A[0-9a-f]{32}\z/D', $bootId)) {
            throw new \InvalidArgumentException('Deployment lease requires a bounded namespace and lowercase 32-hex boot identity.');
        }
    }
}
