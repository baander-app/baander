<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch;

use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzCoverImage;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRecording;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRejected;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzReleaseSummary;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzSlotWait;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Metadata\Application\Port\MusicBrainzLookupInterface;

/** Answers MusicBrainz calls from what a test set, records every call and never touches the network. */
final class FakeMusicBrainzLookup implements MusicBrainzLookupInterface
{
    /** @var list<string> each call as "method:argument|argument" */
    public array $calls = [];

    /** @var array<string, list<MusicBrainzReleaseSummary>|MusicBrainzRejected|MusicBrainzUnavailable> by "title|artist" */
    public array $releaseSearches = [];

    /** @var array<string, MusicBrainzRelease|MusicBrainzRejected|MusicBrainzUnavailable> by release ID; an ID not listed is a 404 */
    public array $releases = [];

    /** @var list<MusicBrainzRecording>|MusicBrainzRejected|MusicBrainzUnavailable */
    public array|MusicBrainzRejected|MusicBrainzUnavailable $recordingSearch = [];

    /** @var array<string, MusicBrainzRecording|MusicBrainzRejected|MusicBrainzUnavailable> by recording ID */
    public array $recordings = [];

    /** @var list<MusicBrainzArtist>|MusicBrainzRejected|MusicBrainzUnavailable */
    public array|MusicBrainzRejected|MusicBrainzUnavailable $artistSearch = [];

    /** @var array<string, MusicBrainzArtist|MusicBrainzRejected|MusicBrainzUnavailable> by artist ID */
    public array $artists = [];

    #[\Override]
    public function findReleases(string $title, ?string $artist, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): array|MusicBrainzRejected|MusicBrainzUnavailable
    {
        $key = $title . '|' . $artist;
        $this->calls[] = 'findReleases:' . $key;

        return $this->releaseSearches[$key] ?? [];
    }

    #[\Override]
    public function fetchRelease(string $releaseId, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): MusicBrainzRelease|MusicBrainzRejected|MusicBrainzUnavailable|null
    {
        $this->calls[] = 'fetchRelease:' . $releaseId;

        return $this->releases[$releaseId] ?? null;
    }

    #[\Override]
    public function findRecordings(string $title, ?string $artist, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): array|MusicBrainzRejected|MusicBrainzUnavailable
    {
        $this->calls[] = 'findRecordings:' . $title . '|' . $artist;

        return $this->recordingSearch;
    }

    #[\Override]
    public function fetchRecording(string $recordingId, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): MusicBrainzRecording|MusicBrainzRejected|MusicBrainzUnavailable|null
    {
        $this->calls[] = 'fetchRecording:' . $recordingId;

        return $this->recordings[$recordingId] ?? null;
    }

    #[\Override]
    public function findArtists(string $name, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): array|MusicBrainzRejected|MusicBrainzUnavailable
    {
        $this->calls[] = 'findArtists:' . $name;

        return $this->artistSearch;
    }

    #[\Override]
    public function fetchArtist(string $artistId, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): MusicBrainzArtist|MusicBrainzRejected|MusicBrainzUnavailable|null
    {
        $this->calls[] = 'fetchArtist:' . $artistId;

        return $this->artists[$artistId] ?? null;
    }

    #[\Override]
    public function fetchFrontCover(string $releaseId, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): MusicBrainzCoverImage|MusicBrainzRejected|MusicBrainzUnavailable|null
    {
        $this->calls[] = 'fetchFrontCover:' . $releaseId;

        return null;
    }

    /** @return list<string> the release IDs looked up, in call order */
    public function fetchedReleases(): array
    {
        return array_filter($this->calls, static fn (string $call): bool => str_starts_with($call, 'fetchRelease:'))
            |> (static fn (array $calls): array => array_map(static fn (string $call): string => substr($call, strlen('fetchRelease:')), $calls))
            |> array_values(...);
    }
}
