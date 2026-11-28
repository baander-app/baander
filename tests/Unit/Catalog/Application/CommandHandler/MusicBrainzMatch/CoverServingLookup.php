<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler\MusicBrainzMatch;

use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzDisabled;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzCoverImage;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzMerged;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRecording;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRejected;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzSlotWait;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Metadata\Application\Port\MusicBrainzLookupInterface;
use App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch\FakeMusicBrainzLookup;

/** The MusicBrainz fake of the match queries, which also serves the front covers a test set. */
final class CoverServingLookup implements MusicBrainzLookupInterface
{
    /** @var array<string, MusicBrainzCoverImage|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled> by release ID; a release not listed has no front cover */
    public array $covers = [];

    public function __construct(
        private readonly FakeMusicBrainzLookup $lookup,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->lookup->isEnabled();
    }

    #[\Override]
    public function findReleases(string $title, ?string $artist, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): array|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled
    {
        return $this->lookup->findReleases($title, $artist, $wait);
    }

    #[\Override]
    public function fetchRelease(string $releaseId, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): MusicBrainzRelease|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled|null
    {
        return $this->lookup->fetchRelease($releaseId, $wait);
    }

    #[\Override]
    public function findRecordings(string $title, ?string $artist, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): array|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled
    {
        return $this->lookup->findRecordings($title, $artist, $wait);
    }

    #[\Override]
    public function fetchRecording(string $recordingId, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): MusicBrainzRecording|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled|null
    {
        return $this->lookup->fetchRecording($recordingId, $wait);
    }

    #[\Override]
    public function findArtists(string $name, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): array|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled
    {
        return $this->lookup->findArtists($name, $wait);
    }

    #[\Override]
    public function fetchArtist(string $artistId, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): MusicBrainzArtist|MusicBrainzMerged|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled|null
    {
        return $this->lookup->fetchArtist($artistId, $wait);
    }

    #[\Override]
    public function fetchFrontCover(string $releaseId, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): MusicBrainzCoverImage|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled|null
    {
        $this->lookup->calls[] = 'fetchFrontCover:' . $releaseId;

        return $this->covers[$releaseId] ?? null;
    }
}
