<?php

declare(strict_types=1);

namespace App\Shared\Application\LiveUpdates;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class LiveSnapshot
{
    /**
     * @param list<LiveFamily> $families
     * @param array<string, string> $revisions
     */
    public function __construct(
        public array $families,
        public string $authorizationFingerprint,
        public array $revisions,
        public LivePipelineHealth $health,
    ) {}
}
