<?php

declare(strict_types=1);

namespace Baander\Tooling\Deployment;

/**
 * Out-of-memory scores of the managed deployment's services. Containers carry no memory limit,
 * so under host memory pressure the kernel picks victims by score: web workers go first, then
 * media work and the execution worker, then Redis, while PostgreSQL, the pooler and the control
 * worker go last. The application sets the worker unit and media work scores itself.
 */
final class KillOrder
{
    public const int WEB = 900;
    public const int REDIS = 100;
    public const int GATEWAY = 0;
    public const int POSTGRES = -500;
    public const int POOLER = -500;

    /**
     * Refuses a daemon that cannot apply the negative scores protecting PostgreSQL, the pooler
     * and the control worker: a rootless daemon may raise scores but never lower them.
     *
     * @param array<array-key,mixed> $dockerInformation `docker info` as decoded JSON
     */
    public static function assertApplicable(array $dockerInformation): void
    {
        $options = $dockerInformation['SecurityOptions'] ?? [];
        if (!is_array($options)) {
            return;
        }
        foreach ($options as $option) {
            if (is_string($option) && explode(',', $option, 2)[0] === 'name=rootless') {
                throw new \RuntimeException('The Docker daemon runs rootless and cannot apply the negative out-of-memory scores that protect PostgreSQL, the pooler and the control worker; use a rootful daemon.');
            }
        }
    }
}
