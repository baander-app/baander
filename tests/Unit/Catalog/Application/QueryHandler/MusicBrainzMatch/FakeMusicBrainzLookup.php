<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch;

use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzDisabled;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzCoverImage;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzMerged;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRecording;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRejected;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzReleaseSummary;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzSlotWait;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Metadata\Application\Port\MusicBrainzLookupInterface;
use Closure;

/** Answers MusicBrainz calls from what a test set, records every call and never touches the network. */
final class FakeMusicBrainzLookup implements MusicBrainzLookupInterface
{
    /** @var list<string> each call as "method:argument|argument" */
    public array $calls = [];

    /** @var array<string, list<MusicBrainzReleaseSummary>|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled> by "title|artist" */
    public array $releaseSearches = [];

    /** @var array<string, MusicBrainzRelease|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled> by release ID; an ID not listed is a 404 */
    public array $releases = [];

    /** @var list<MusicBrainzRecording>|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled */
    public array|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled $recordingSearch = [];

    /** @var array<string, MusicBrainzRecording|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled> by recording ID */
    public array $recordings = [];

    /** @var list<MusicBrainzArtist>|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled */
    public array|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled $artistSearch = [];

    /** @var array<string, MusicBrainzArtist|MusicBrainzMerged|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled> by artist ID; an ID not listed is a 404 */
    public array $artists = [];

    /**
     * Runs during a release lookup, before the answer: what another session commits while
     * MusicBrainz is asked, between the caller's read of the catalog and its writes.
     *
     * @var (Closure(string): void)|null given the release ID looked up
     */
    public ?Closure $duringReleaseLookup = null;

    /**
     * Runs during an artist lookup, before the answer, as {@see $duringReleaseLookup} does for a
     * release.
     *
     * @var (Closure(string): void)|null given the artist ID looked up
     */
    public ?Closure $duringArtistLookup = null;

    public bool $enabled = true;

    /** @phpstan-pure */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    #[\Override]
    public function findReleases(string $title, ?string $artist, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): array|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled
    {
        $key = $title . '|' . $artist;
        $this->calls[] = 'findReleases:' . $key;

        return $this->releaseSearches[$key] ?? [];
    }

    #[\Override]
    public function fetchRelease(string $releaseId, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): MusicBrainzRelease|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled|null
    {
        $this->calls[] = 'fetchRelease:' . $releaseId;
        $this->duringReleaseLookup?->__invoke($releaseId);

        return $this->releases[$releaseId] ?? null;
    }

    #[\Override]
    public function findRecordings(string $title, ?string $artist, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): array|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled
    {
        $this->calls[] = 'findRecordings:' . $title . '|' . $artist;

        return $this->recordingSearch;
    }

    #[\Override]
    public function fetchRecording(string $recordingId, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): MusicBrainzRecording|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled|null
    {
        $this->calls[] = 'fetchRecording:' . $recordingId;

        return $this->recordings[$recordingId] ?? null;
    }

    #[\Override]
    public function findArtists(string $name, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): array|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled
    {
        $this->calls[] = 'findArtists:' . $name;

        return $this->artistSearch;
    }

    #[\Override]
    public function fetchArtist(string $artistId, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): MusicBrainzArtist|MusicBrainzMerged|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled|null
    {
        $this->calls[] = 'fetchArtist:' . $artistId;
        $this->duringArtistLookup?->__invoke($artistId);

        return $this->artists[$artistId] ?? null;
    }

    #[\Override]
    public function fetchFrontCover(string $releaseId, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): MusicBrainzCoverImage|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled|null
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
