<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
final readonly class MediaWorkerIdentity
{
    public function __construct(
        public string $namespace,
        public string $bootId,
        public string $workerId,
        public int $launchGeneration,
        public int $leaseEpoch = 1,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $namespace) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $bootId) !== 1
            || preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $workerId) !== 1
            || $launchGeneration < 1 || $leaseEpoch < 1
        ) {
            throw new \InvalidArgumentException('Invalid media worker launch identity.');
        }
    }
}
