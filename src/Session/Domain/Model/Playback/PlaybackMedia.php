<?php

declare(strict_types=1);

namespace App\Session\Domain\Model\Playback;

use App\Shared\Domain\Model\PublicId;
use InvalidArgumentException;

final readonly class PlaybackMedia
{
    public function __construct(public string $kind, public PublicId $publicId)
    {
        if (!in_array($kind, ['song', 'video'], true)) {
            throw new InvalidArgumentException('Unsupported playback media kind.');
        }
    }

    /** @return array{kind:string,publicId:string} */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'publicId' => $this->publicId->toString()];
    }
}
