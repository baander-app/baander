<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\Video;

final readonly class VideoCandidate
{
    /**
     * @param array<string, string> $externalIds
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?int $year = null,
        public array $externalIds = [],
    ) {}
}
