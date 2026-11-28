<?php

declare(strict_types=1);

namespace App\Session\Domain\Model\Playback;

use App\Shared\Domain\Model\PublicId;
use InvalidArgumentException;

final readonly class PlaybackMedia
{
    public function __construct(
        public string $kind,
        public PublicId $publicId,
        public ?PlaybackViewingContext $viewing = null,
    ) {
        if (!in_array($kind, ['song', 'video'], true)) {
            throw new InvalidArgumentException('Unsupported playback media kind.');
        }
        if ($viewing !== null && $kind !== 'video') {
            throw new InvalidArgumentException('Only video playback may carry a viewing context.');
        }
    }

    /** @param array<string, mixed> $media */
    public static function fromArray(array $media): self
    {
        if (!is_string($media['kind'] ?? null) || !is_string($media['publicId'] ?? null)) {
            throw new InvalidArgumentException('Media requires a song or video public identity.');
        }
        $viewing = null;
        if (array_key_exists('viewing', $media)) {
            if (!is_array($media['viewing'])) {
                throw new InvalidArgumentException('Playback viewing context must be an object.');
            }
            $viewing = PlaybackViewingContext::fromArray($media['viewing']);
        }

        return new self($media['kind'], PublicId::fromString($media['publicId']), $viewing);
    }

    public function matches(?self $other): bool
    {
        return $other !== null && $this->kind === $other->kind && $this->publicId->toString() === $other->publicId->toString();
    }

    /** @return array{kind:string,publicId:string,viewing?:array{titleKind:string,titleId:string}} */
    public function toArray(): array
    {
        $media = ['kind' => $this->kind, 'publicId' => $this->publicId->toString()];
        if ($this->viewing !== null) {
            $media['viewing'] = $this->viewing->toArray();
        }

        return $media;
    }
}
