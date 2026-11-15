<?php

declare(strict_types=1);

namespace App\Catalog\Application\QueryHandler\MusicBrainzMatch;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Application\Query\MusicBrainzMatch\AlbumSnapshot;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchCatalogReadInterface;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Application\Query\MusicBrainzMatch\SongSnapshot;
use App\Catalog\Application\Service\CatalogInput;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\Model\Song;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Uuid;

/**
 * Reads the album, song or artist a match is about, with what scoring and comparing it needs,
 * before anything asks MusicBrainz. Every library is searched: matching is for admins. An item
 * that does not exist is reported with `item_not_found` in `details.reason`.
 */
final readonly class MatchSnapshots
{
    /** The reason a not-found answer carries when the item is unknown or was deleted. */
    public const string ITEM_NOT_FOUND_REASON = 'item_not_found';

    public function __construct(
        private AlbumPortInterface $albums,
        private SongPortInterface $songs,
        private ArtistPortInterface $artists,
        private MatchCatalogReadInterface $catalog,
    ) {
    }

    /**
     * @throws InvalidInputException when the public ID is malformed
     * @throws NotFoundException     when no album has it
     */
    public function album(string $publicId): AlbumSnapshot
    {
        $album = (CatalogInput::publicId($publicId) |> $this->albums->findByPublicId(...))
            ?? throw self::notFound(MatchItemType::Album, $publicId);

        return $this->ofAlbum($album);
    }

    /** The album as it is now, with its songs sorted by disc and track, their credits and its genres. */
    public function ofAlbum(Album $album): AlbumSnapshot
    {
        $songs = $this->songs->findByAlbumSortedByTrack($album->getId()) |> array_values(...);

        return new AlbumSnapshot(
            album: $album,
            songs: $songs,
            songCredits: array_map(static fn (Song $song): Uuid => $song->getId(), $songs) |> $this->catalog->songCredits(...),
            credits: $this->catalog->albumCredits($album->getId()),
            genres: $this->catalog->albumGenres($album->getId()),
        );
    }

    /**
     * @throws InvalidInputException when the public ID is malformed
     * @throws NotFoundException     when no song has it
     */
    public function song(string $publicId): SongSnapshot
    {
        $song = (CatalogInput::publicId($publicId) |> $this->songs->findByPublicId(...))
            ?? throw self::notFound(MatchItemType::Song, $publicId);

        return $this->ofSong($song);
    }

    /** The song as it is now, with its credits. */
    public function ofSong(Song $song): SongSnapshot
    {
        return new SongSnapshot($song, $this->catalog->songCredits([$song->getId()])[$song->getId()->toString()] ?? []);
    }

    /**
     * @throws InvalidInputException when the public ID is malformed
     * @throws NotFoundException     when no artist has it
     */
    public function artist(string $publicId): Artist
    {
        return (CatalogInput::publicId($publicId) |> $this->artists->findByPublicId(...))
            ?? throw self::notFound(MatchItemType::Artist, $publicId);
    }

    private static function notFound(MatchItemType $type, string $publicId): NotFoundException
    {
        return new NotFoundException(sprintf('%s "%s" not found.', $type->label(), $publicId), ['reason' => self::ITEM_NOT_FOUND_REASON]);
    }
}
