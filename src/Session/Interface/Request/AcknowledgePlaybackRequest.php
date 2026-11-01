<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class AcknowledgePlaybackRequest
{
    public function __construct(
        #[Assert\Uuid]
        public string $commandId,
        #[Assert\Uuid]
        public string $attemptId,
        #[Assert\PositiveOrZero]
        public int $revision,
        #[Assert\Uuid]
        public string $incarnationId,
        #[Assert\Positive]
        public int $epoch,
        #[Assert\Choice(choices: ['prepared', 'quiesced', 'started', 'stopped', 'playing', 'paused', 'applied', 'rejected'])]
        public string $outcome,
        #[Assert\PositiveOrZero]
        public ?float $position = null,
        #[Assert\Range(min: 0, max: 1)]
        public ?float $volume = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter(get_object_vars($this), static fn(mixed $value): bool => $value !== null);
    }
}
