<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Album;

use App\Catalog\Application\Command\Album\DeleteAlbumCommand;
use App\Catalog\Application\Command\CatalogDeletionResult;
use App\Catalog\Application\Service\CatalogInput;
use App\Catalog\Application\Service\CoverImageDiscarder;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Song;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes an album with every song on it.
 *
 * With the files, the delete claims the album's library first, so no scan runs and no import
 * writes songs until it ends, and releases the claim however it ends; the songs are read after
 * the claim, and every song path is checked before anything changes; the album, its songs and
 * their file index rows go in one transaction, and the files are unlinked after the commit. The
 * cover image is deleted after the commit too, so a rollback leaves the album with its cover.
 */
final readonly class DeleteAlbumHandler
{
    public function __construct(
        private AlbumPortInterface $albums,
        private SongPortInterface $songs,
        private LibraryMediaFilesInterface $mediaFiles,
        private TransactionPortInterface $transaction,
        private CoverImageDiscarder $covers,
    ) {
    }

    /**
     * @throws InvalidInputException when the public ID is malformed, or a song file lies outside the library root
     * @throws NotFoundException     when no album has the public ID
     * @throws ConflictException     with the files, when a scan or another delete holds the library, the library folder is unavailable, every file is already missing, or the server cannot write a song's directory
     */
    #[AsMessageHandler]
    public function __invoke(DeleteAlbumCommand $command): CatalogDeletionResult
    {
        $album = $this->albums->findByPublicId(CatalogInput::publicId($command->publicId))
            ?? throw new NotFoundException(sprintf('Album "%s" not found.', $command->publicId));
        $coverImageId = $command->deleteCover ? $album->getCoverImageId() : null;

        $claim = $command->deleteFiles ? $this->mediaFiles->claim($album->getLibraryId()) : null;
        try {
            // Read after the claim: an import's songs commit before the claim does, and no import
            // adds one after it, so every song the album delete removes has its path checked here.
            $songs = $this->songs->findByAlbumSortedByTrack($album->getId());
            $deletion = $claim !== null
                ? $this->mediaFiles->prepareDeletion($claim, array_map(static fn (Song $song): string => $song->getPath(), $songs))
                : null;

            $this->transaction->run(function () use ($album, $deletion): void {
                $this->albums->delete($album, deleteCover: false);
                if ($deletion !== null) {
                    $this->mediaFiles->deleteIndexRows($deletion);
                }
            });

            if ($coverImageId !== null) {
                $this->covers->discard($coverImageId);
            }

            $files = $claim !== null && $deletion !== null ? $this->mediaFiles->deleteFiles($claim, $deletion) : null;
        } finally {
            if ($claim !== null) {
                $this->mediaFiles->release($claim);
            }
        }

        return CatalogDeletionResult::of(
            ['albums' => 1, 'songs' => count($songs), 'coverImages' => $coverImageId !== null ? 1 : 0],
            $files,
        );
    }
}
