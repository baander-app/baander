<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** A library song as matching compares it with MusicBrainz recordings. */
#[Exclude]
final readonly class LocalSong
{
    /**
     * @param list<string> $artistNames the names the song credits, primary first
     * @param float|null $lengthSeconds the length measured from the audio
     */
    public function __construct(
        public string $title,
        public array $artistNames,
        public ?float $lengthSeconds,
    ) {
    }
}
