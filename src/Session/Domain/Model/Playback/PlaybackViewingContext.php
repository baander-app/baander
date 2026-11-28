<?php

declare(strict_types=1);

namespace App\Session\Domain\Model\Playback;

use App\Shared\Domain\Model\PublicId;
use InvalidArgumentException;

final readonly class PlaybackViewingContext
{
    public function __construct(public string $titleKind, public PublicId $titleId)
    {
        if (!in_array($titleKind, ['episode', 'movie', 'extra'], true)) {
            throw new InvalidArgumentException('Unsupported playback title kind.');
        }
    }

    /** @param array<string, mixed> $viewing */
    public static function fromArray(array $viewing): self
    {
        if (!is_string($viewing['titleKind'] ?? null) || !is_string($viewing['titleId'] ?? null)) {
            throw new InvalidArgumentException('Playback viewing context requires a title kind and public identity.');
        }

        return new self($viewing['titleKind'], PublicId::fromString($viewing['titleId']));
    }

    /** @return array{titleKind:string,titleId:string} */
    public function toArray(): array
    {
        return ['titleKind' => $this->titleKind, 'titleId' => $this->titleId->toString()];
    }
}
