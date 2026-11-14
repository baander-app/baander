<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One line of an album comparison: a local song paired with a release track, with the song's rows,
 * or a track or song left unpaired on either side. An apply writes paired songs only.
 */
#[Exclude]
final readonly class MatchTrack
{
    /**
     * @param ComparedSong|null         $song    null for a release track no local song pairs with
     * @param ComparedReleaseTrack|null $release null for a local song that pairs with no release track
     * @param list<MatchFieldRow>       $fields  the song's rows; empty unless paired
     * @param MatchCreditsRow|null      $credits the song's credits row; null unless paired
     */
    public function __construct(
        public ?ComparedSong $song,
        public ?ComparedReleaseTrack $release,
        public array $fields = [],
        public ?MatchCreditsRow $credits = null,
    ) {
    }

    public function isPaired(): bool
    {
        return $this->song !== null && $this->release !== null;
    }
}
