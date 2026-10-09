<?php

declare(strict_types=1);

namespace App\Catalog\Application\QueryHandler\Song;

use App\Catalog\Application\CommandHandler\CatalogInput;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Application\Query\FileDeletionPreview;
use App\Catalog\Application\Query\Song\GetSongDeletePreviewQuery;
use App\Catalog\Application\Query\Song\SongDeletePreview;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Playlist\Application\Port\PlaylistDeletionPreviewPortInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Changes nothing. */
final readonly class GetSongDeletePreviewHandler
{
    public function __construct(
        private SongPortInterface $songs,
        private AlbumPortInterface $albums,
        private PlaylistDeletionPreviewPortInterface $playlists,
        private LibraryMediaFilesInterface $mediaFiles,
    ) {
    }

    /**
     * @throws InvalidInputException when the public ID is malformed
     * @throws NotFoundException     when no song has the public ID
     */
    #[AsMessageHandler]
    public function __invoke(GetSongDeletePreviewQuery $query): SongDeletePreview
    {
        $song = $this->songs->findByPublicId(CatalogInput::publicId($query->publicId))
            ?? throw new NotFoundException(sprintf('Song "%s" not found.', $query->publicId));
        $album = $this->albums->findByUuid($song->getAlbumId());

        $fileDeletion = null;
        if ($query->deleteFile) {
            if ($album === null) {
                throw new NotFoundException(sprintf('The album of song "%s" was not found.', $query->publicId));
            }
            $fileDeletion = FileDeletionPreview::fromInspection($this->mediaFiles->inspect($album->getLibraryId(), [$song->getPath()]));
        }

        return new SongDeletePreview(
            publicId: $song->getPublicId()->toString(),
            title: $song->getTitle(),
            album: $album !== null ? ['id' => $album->getPublicId()->toString(), 'title' => $album->getTitle()] : null,
            path: $song->getPath(),
            size: $song->getSize(),
            playlistNames: array_column($this->playlists->findContainingSongs([$song->getId()]), 'name'),
            fileDeletion: $fileDeletion,
        );
    }
}
