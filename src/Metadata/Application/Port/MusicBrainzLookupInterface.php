<?php

declare(strict_types=1);

namespace App\Metadata\Application\Port;

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

/**
 * Port to the MusicBrainz web service and the Cover Art Archive, for matching library items
 * to releases, recordings and artists.
 *
 * Every call first takes a turn under one rate limit that all processes share, so together
 * they stay within MusicBrainz's one request per second. A bounded call gives up after ten
 * seconds without a turn; an unbounded one waits as long as the queue ahead of it needs.
 *
 * No method throws. A switched-off provider answers MusicBrainzDisabled without a request.
 * A lookup returns null when the service has no such record (404). Every
 * method returns MusicBrainzRejected when the service refused the request with another client
 * error, and MusicBrainzUnavailable when it gave no usable answer, so callers can tell a miss,
 * a refused request and an outage apart. A search that finds nothing returns an empty list; a
 * 404 from a search, which has no record to miss, counts as rejected.
 *
 * Searches quote and escape every value, so titles such as "AC/DC" or "Re: Foo" are searched
 * as text, and return at most 25 hits in MusicBrainz's order.
 */
interface MusicBrainzLookupInterface
{
    /**
     * Reads the current provider switch without contacting MusicBrainz.
     *
     * @phpstan-impure
     */
    public function isEnabled(): bool;

    /**
     * Search releases (editions) by title and, when an artist is given, by artist credit.
     * Without an artist, such as for a compilation, the title alone is searched.
     *
     * @return list<MusicBrainzReleaseSummary>|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled
     */
    public function findReleases(
        string $title,
        ?string $artist,
        MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded,
    ): array|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled;

    /** Look up a release with its media, tracks, labels, artist credits, release group and genres. */
    public function fetchRelease(
        string $releaseId,
        MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded,
    ): MusicBrainzRelease|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled|null;

    /**
     * Search recordings by title and, when an artist is given, by artist credit.
     *
     * @return list<MusicBrainzRecording>|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled
     */
    public function findRecordings(
        string $title,
        ?string $artist,
        MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded,
    ): array|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled;

    /** Look up a recording with its artist credit and the releases it appears on. */
    public function fetchRecording(
        string $recordingId,
        MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded,
    ): MusicBrainzRecording|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled|null;

    /**
     * Search artists by name.
     *
     * @return list<MusicBrainzArtist>|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled
     */
    public function findArtists(
        string $name,
        MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded,
    ): array|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled;

    /**
     * Look up an artist. An ID MusicBrainz merged into another artist answers MusicBrainzMerged
     * with the surviving artist's ID, without a request for that artist.
     */
    public function fetchArtist(
        string $artistId,
        MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded,
    ): MusicBrainzArtist|MusicBrainzMerged|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled|null;

    /**
     * Fetch a release's front cover from the Cover Art Archive, at most 1200 pixels wide.
     * Returns null when the release has no front cover.
     */
    public function fetchFrontCover(
        string $releaseId,
        MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded,
    ): MusicBrainzCoverImage|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled|null;
}
