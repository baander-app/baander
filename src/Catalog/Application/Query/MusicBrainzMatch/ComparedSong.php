<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

use App\Catalog\Domain\Model\Song;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** A local song as an album comparison pairs it with a release track. */
#[Exclude]
final readonly class ComparedSong
{
    /**
     * @param int|null   $disc          the tagged disc number
     * @param int|null   $track         the tagged track number
     * @param float|null $lengthSeconds the length measured from the audio
     */
    public function __construct(
        public string $publicId,
        public string $title,
        public ?int $disc,
        public ?int $track,
        public ?float $lengthSeconds,
    ) {
    }

    public static function of(Song $song): self
    {
        return new self($song->getPublicId()->toString(), $song->getTitle(), $song->getDisc(), $song->getTrack(), $song->getLength());
    }
}
