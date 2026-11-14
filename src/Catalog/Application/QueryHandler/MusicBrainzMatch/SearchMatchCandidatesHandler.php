<?php

declare(strict_types=1);

namespace App\Catalog\Application\QueryHandler\MusicBrainzMatch;

use App\Catalog\Application\Query\MusicBrainzMatch\ArtistCandidate;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchCandidates;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Application\Query\MusicBrainzMatch\RecordingCandidate;
use App\Catalog\Application\Query\MusicBrainzMatch\ReleaseCandidate;
use App\Catalog\Application\Query\MusicBrainzMatch\SearchMatchCandidatesQuery;
use App\Catalog\Application\Service\MusicBrainzMatch\CandidateScorer;
use App\Catalog\Application\Service\MusicBrainzMatch\LocalAlbum;
use App\Catalog\Application\Service\MusicBrainzMatch\LocalArtist;
use App\Catalog\Application\Service\MusicBrainzMatch\LocalSong;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRecording;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzReleaseSummary;
use App\Metadata\Application\Port\MusicBrainzLookupInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use Closure;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles SearchMatchCandidatesQuery: the item is read first, so an unknown item never spends a
 * MusicBrainz request. Candidates are scored by their fit to the item, compared with the title and
 * artist searched: the admin's edited ones when given, the item's own otherwise.
 *
 * - An album runs one release search on its title and primary artist (the title alone when it has
 *   none), ranks the hits by title, artist and track count, looks up the best five and scores them
 *   by their tracks too.
 * - A song runs one recording search on its title and the artist text it shows.
 * - An artist runs one artist search on its name.
 *
 * Every list holds at most five candidates, best first; candidates that score alike keep
 * MusicBrainz's order. A hit whose lookup finds nothing is left out; any MusicBrainz outage fails the
 * whole search rather than shortening the list.
 */
#[AsMessageHandler]
final readonly class SearchMatchCandidatesHandler
{
    /** How many candidates a search lists, and how many release hits an album search looks up. */
    public const int CANDIDATE_LIMIT = 5;

    public function __construct(
        private MusicBrainzLookupInterface $musicBrainz,
        private CandidateScorer $scorer,
        private MatchSnapshots $snapshots,
    ) {
    }

    /**
     * @throws InvalidInputException       when the public ID is malformed
     * @throws NotFoundException           when no such item exists
     * @throws ServiceUnavailableException when MusicBrainz did not answer the search or a lookup (`musicbrainz_unavailable`)
     * @throws ConflictException           when MusicBrainz rejected a request (`musicbrainz_rejected`)
     */
    public function __invoke(SearchMatchCandidatesQuery $query): MatchCandidates
    {
        return match ($query->type) {
            MatchItemType::Album => $this->albumCandidates($query),
            MatchItemType::Song => $this->songCandidates($query),
            MatchItemType::Artist => $this->artistCandidates($query),
        };
    }

    private function albumCandidates(SearchMatchCandidatesQuery $query): MatchCandidates
    {
        $snapshot = $this->snapshots->album($query->publicId);
        $title = self::edited($query->title) ?? $snapshot->album->getTitle();
        $artist = self::edited($query->artist) ?? $snapshot->primaryArtistName();
        $album = $snapshot->localAlbum($title, $artist);

        $hits = $this->musicBrainz->findReleases($title, $artist) |> MusicBrainzAnswer::unwrap(...);
        $candidates = self::best($hits, fn (MusicBrainzReleaseSummary $hit): int => $this->scorer->scoreReleaseSummary($hit, $album))
            |> (fn (array $best): array => array_map($this->lookUpRelease(...), $best))
            |> array_filter(...)
            |> (fn (array $releases): array => array_map(fn (MusicBrainzRelease $release): ReleaseCandidate => $this->releaseCandidate($release, $album), $releases))
            |> array_values(...)
            |> (static fn (array $scored): array => self::best($scored, static fn (ReleaseCandidate $candidate): int => $candidate->score));

        return new MatchCandidates(MatchItemType::Album, $query->publicId, $title, $artist, $candidates);
    }

    private function songCandidates(SearchMatchCandidatesQuery $query): MatchCandidates
    {
        $snapshot = $this->snapshots->song($query->publicId);
        $title = self::edited($query->title) ?? $snapshot->song->getTitle();
        $editedArtist = self::edited($query->artist);
        $artist = $editedArtist ?? $snapshot->primaryArtistName();
        $song = $snapshot->localSong($title, $editedArtist);

        $candidates = $this->musicBrainz->findRecordings($title, $artist)
            |> MusicBrainzAnswer::unwrap(...)
            |> (fn (array $hits): array => array_map(fn (MusicBrainzRecording $hit): RecordingCandidate => $this->recordingCandidate($hit, $song), $hits))
            |> (static fn (array $scored): array => self::best($scored, static fn (RecordingCandidate $candidate): int => $candidate->score));

        return new MatchCandidates(MatchItemType::Song, $query->publicId, $title, $artist, $candidates);
    }

    private function artistCandidates(SearchMatchCandidatesQuery $query): MatchCandidates
    {
        $stored = $this->snapshots->artist($query->publicId);
        $name = self::edited($query->title) ?? $stored->getName();
        $artist = new LocalArtist($name, $stored->getDisambiguation());

        $candidates = $this->musicBrainz->findArtists($name)
            |> MusicBrainzAnswer::unwrap(...)
            |> (fn (array $hits): array => array_map(fn (MusicBrainzArtist $hit): ArtistCandidate => ArtistCandidate::of($hit, $this->scorer->scoreArtist($hit, $artist)), $hits))
            |> (static fn (array $scored): array => self::best($scored, static fn (ArtistCandidate $candidate): int => $candidate->score));

        return new MatchCandidates(MatchItemType::Artist, $query->publicId, $name, null, $candidates);
    }

    /** The release with its tracks; null when MusicBrainz no longer has it. */
    private function lookUpRelease(MusicBrainzReleaseSummary $hit): ?MusicBrainzRelease
    {
        return $this->musicBrainz->fetchRelease($hit->id) |> MusicBrainzAnswer::unwrap(...);
    }

    private function releaseCandidate(MusicBrainzRelease $release, LocalAlbum $album): ReleaseCandidate
    {
        return ReleaseCandidate::of($release, $this->scorer->scoreRelease($release, $album)->score);
    }

    private function recordingCandidate(MusicBrainzRecording $recording, LocalSong $song): RecordingCandidate
    {
        return RecordingCandidate::of($recording, $this->scorer->scoreRecording($recording, $song));
    }

    /**
     * The best-scoring items, at most CANDIDATE_LIMIT, best first; equal scores keep their order.
     *
     * @template T
     *
     * @param list<T>         $items
     * @param Closure(T): int $score
     *
     * @return list<T>
     */
    private static function best(array $items, Closure $score): array
    {
        $scores = array_map($score, $items);
        $order = array_keys($items);
        usort($order, static fn (int $one, int $other): int => $scores[$other] <=> $scores[$one]);

        return array_slice($order, 0, self::CANDIDATE_LIMIT)
            |> (static fn (array $indexes): array => array_map(static fn (int $index): mixed => $items[$index], $indexes));
    }

    /** The admin's edit, trimmed; null when there is none or it is blank. */
    private static function edited(?string $value): ?string
    {
        $value = trim($value ?? '');

        return $value === '' ? null : $value;
    }
}
