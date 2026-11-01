<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ObservePlaybackRequest
{
    public function __construct(
        #[Assert\Uuid]
        public string $incarnationId,
        #[Assert\PositiveOrZero]
        public int $expectedRevision,
        #[Assert\Choice(choices: ['playing', 'paused', 'stopped', 'unknown'])]
        public string $observedState,
        #[Assert\PositiveOrZero]
        public float $position,
        #[Assert\Range(min: 0, max: 1)]
        public ?float $volume = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter(get_object_vars($this), static fn(mixed $value): bool => $value !== null);
    }
}
