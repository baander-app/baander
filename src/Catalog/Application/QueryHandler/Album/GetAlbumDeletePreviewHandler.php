<?php

declare(strict_types=1);

namespace App\Catalog\Application\QueryHandler\Album;

use App\Catalog\Application\CommandHandler\CatalogInput;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Application\Query\Album\AlbumDeletePreview;
use App\Catalog\Application\Query\Album\GetAlbumDeletePreviewQuery;
use App\Catalog\Application\Query\FileDeletionPreview;
use App\Catalog\Domain\Model\Song;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Playlist\Application\Port\PlaylistDeletionPreviewPortInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Counts every song of the album, as DeleteAlbumHandler deletes every one. Changes nothing. */
final readonly class GetAlbumDeletePreviewHandler
{
    public function __construct(
        private AlbumPortInterface $albums,
        private SongPortInterface $songs,
        private PlaylistDeletionPreviewPortInterface $playlists,
        private LibraryMediaFilesInterface $mediaFiles,
    ) {
    }

    /**
     * @throws InvalidInputException when the public ID is malformed
     * @throws NotFoundException     when no album has the public ID
     */
    #[AsMessageHandler]
    public function __invoke(GetAlbumDeletePreviewQuery $query): AlbumDeletePreview
    {
        $album = $this->albums->findByPublicId(CatalogInput::publicId($query->publicId))
            ?? throw new NotFoundException(sprintf('Album "%s" not found.', $query->publicId));
        $songs = $this->songs->findByAlbumSortedByTrack($album->getId());

        $playlists = $this->playlists->findContainingSongs(array_map(static fn (Song $song): Uuid => $song->getId(), $songs));

        return new AlbumDeletePreview(
            publicId: $album->getPublicId()->toString(),
            title: $album->getTitle(),
            songCount: count($songs),
            totalSize: array_sum(array_map(static fn (Song $song): int => $song->getSize(), $songs)),
            coverImageId: $album->getCoverImageId()?->toString(),
            playlistNames: array_column($playlists, 'name'),
            fileDeletion: $query->deleteFiles
                ? FileDeletionPreview::fromInspection($this->mediaFiles->inspect(
                    $album->getLibraryId(),
                    array_map(static fn (Song $song): string => $song->getPath(), $songs),
                ))
                : null,
        );
    }
}
