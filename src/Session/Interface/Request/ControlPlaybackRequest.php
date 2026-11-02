<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ControlPlaybackRequest
{
    /**
     * @param array{kind:string,publicId:string}|null $media
     * @param list<string>|null $queue
     */
    public function __construct(
        #[Assert\Uuid]
        public string $requestId,
        #[Assert\PositiveOrZero]
        public int $expectedRevision,
        #[Assert\Choice(choices: ['play', 'pause', 'seek', 'load', 'stop', 'volume', 'queue'])]
        public string $action,
        #[Assert\PositiveOrZero]
        public ?float $position = null,
        #[Assert\Collection(fields: ['kind' => new Assert\Choice(choices: ['song', 'video']), 'publicId' => new Assert\Regex(pattern: '/^[0-9a-zA-Z_-]{21}$/')])]
        public ?array $media = null,
        #[Assert\All(constraints: [new Assert\Regex(pattern: '/^[0-9a-zA-Z_-]{21}$/')])]
        public ?array $queue = null,
        #[Assert\PositiveOrZero]
        public ?int $currentTrackIndex = null,
        #[Assert\Choice(choices: ['playing', 'paused', 'stopped'])]
        public ?string $desiredState = null,
        #[Assert\Range(min: 0, max: 1)]
        public ?float $volume = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter(get_object_vars($this), static fn(mixed $value): bool => $value !== null);
    }
}
