<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Song;

use App\Catalog\Application\Command\CatalogDeletionResult;
use App\Catalog\Application\Command\Song\DeleteSongCommand;
use App\Catalog\Application\Service\CatalogInput;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes a song. With its file, the path is checked before anything changes; the song and its
 * file index row go in one transaction, and the file is unlinked after the commit.
 */
final readonly class DeleteSongHandler
{
    public function __construct(
        private SongPortInterface $songs,
        private AlbumPortInterface $albums,
        private LibraryMediaFilesInterface $mediaFiles,
        private TransactionPortInterface $transaction,
    ) {
    }

    /**
     * @throws InvalidInputException when the public ID is malformed, or the file lies outside the library root
     * @throws NotFoundException     when no song has the public ID
     * @throws ConflictException     with the file, when a scan holds the library or the server cannot write the song's directory
     */
    #[AsMessageHandler]
    public function __invoke(DeleteSongCommand $command): CatalogDeletionResult
    {
        $song = $this->songs->findByPublicId(CatalogInput::publicId($command->publicId))
            ?? throw new NotFoundException(sprintf('Song "%s" not found.', $command->publicId));

        $deletion = null;
        if ($command->deleteFile) {
            // songs.album_id is a non-null foreign key, so a song's album exists while the song does.
            $album = $this->albums->findByUuid($song->getAlbumId())
                ?? throw new NotFoundException(sprintf('The album of song "%s" was not found.', $command->publicId));
            $deletion = $this->mediaFiles->prepareDeletion($album->getLibraryId(), [$song->getPath()]);
        }

        $this->transaction->run(function () use ($song, $deletion): void {
            $this->songs->delete($song);
            if ($deletion !== null) {
                $this->mediaFiles->deleteIndexRows($deletion);
            }
        });

        return CatalogDeletionResult::of(
            ['songs' => 1],
            $deletion !== null ? $this->mediaFiles->deleteFiles($deletion) : null,
        );
    }
}
