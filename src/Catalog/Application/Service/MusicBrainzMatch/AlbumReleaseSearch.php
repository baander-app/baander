<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

use App\Catalog\Application\QueryHandler\MusicBrainzMatch\MusicBrainzAnswer;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzReleaseSummary;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzSlotWait;
use App\Metadata\Application\Port\MusicBrainzLookupInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\ServiceUnavailableException;

/**
 * Searches MusicBrainz for the releases of an album by the title the library stores, for an
 * admin's candidate list and for the album match queue alike.
 *
 * The stored title is searched first. Only when that search returns no hit, and the title ends in
 * a comma-separated list in square brackets, is the title searched once more without the list. The
 * answer names the title searched last, whatever the second search found, and candidates are
 * scored against that title. The stored title is never changed.
 */
final readonly class AlbumReleaseSearch
{
    public function __construct(
        private MusicBrainzLookupInterface $musicBrainz,
    ) {
    }

    /**
     * @param string|null $artist the artist credit to search with; null searches by the title alone
     *
     * @throws ServiceUnavailableException when MusicBrainz did not answer either search (`musicbrainz_unavailable`)
     * @throws ConflictException           when MusicBrainz rejected either search (`musicbrainz_rejected`)
     */
    public function find(string $title, ?string $artist, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): ReleaseSearchHits
    {
        $hits = $this->hits($title, $artist, $wait);
        $shortened = ReleaseSearchTitle::shortened($title);
        if ($hits !== [] || $shortened === $title) {
            return new ReleaseSearchHits($title, $hits);
        }

        return new ReleaseSearchHits($shortened, $this->hits($shortened, $artist, $wait));
    }

    /** @return list<MusicBrainzReleaseSummary> */
    private function hits(string $title, ?string $artist, MusicBrainzSlotWait $wait): array
    {
        return $this->musicBrainz->findReleases($title, $artist, $wait) |> MusicBrainzAnswer::unwrap(...);
    }
}
