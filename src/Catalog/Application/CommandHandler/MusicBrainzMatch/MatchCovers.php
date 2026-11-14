<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\MusicBrainzMatch;

use App\Catalog\Application\QueryHandler\MusicBrainzMatch\MusicBrainzAnswer;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Domain\Model\Image;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzSlotWait;
use App\Metadata\Application\Port\MusicBrainzLookupInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Domain\Model\Uuid;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The front cover a match gives an album. It is downloaded and stored as a file of its own before
 * the apply's transaction, so no lock waits on the Cover Art Archive; the transaction saves the
 * image record and the album's cover, and the file is removed again when it does not.
 */
final readonly class MatchCovers
{
    public const int MAX_SIZE = 10 * 1024 * 1024;

    private const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private MusicBrainzLookupInterface $musicBrainz,
        private StoragePortInterface $storage,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Downloads the release's front cover and stores it as a file for the album. The image is not
     * saved: the apply saves it, or hands it to discard().
     *
     * @return Image|null null when the release has no front cover, or one that is not a JPEG, PNG or
     *                    WebP image of at most 10 MB
     *
     * @throws ServiceUnavailableException when the Cover Art Archive did not answer
     * @throws ConflictException           when the Cover Art Archive refused the request
     */
    public function fetch(Uuid $albumId, string $releaseId, MusicBrainzSlotWait $wait): ?Image
    {
        $cover = $this->musicBrainz->fetchFrontCover($releaseId, $wait) |> MusicBrainzAnswer::unwrap(...);
        $extension = self::EXTENSIONS[$cover->mimeType ?? ''] ?? null;
        if ($cover === null || $extension === null || $cover->bytes === '' || strlen($cover->bytes) > self::MAX_SIZE) {
            $this->logger->info('The release has no front cover a match can use', [
                'album_id' => $albumId->toString(),
                'release_id' => $releaseId,
                'mime_type' => $cover?->mimeType,
            ]);

            return null;
        }

        // A path of its own, so the file never replaces the cover of an apply that ran at the same time.
        $path = sprintf('images/album/%s/%s.%s', $albumId->toString(), Uuid::generate()->toString(), $extension);
        $stored = $this->storage->storeFromBytes($cover->bytes, $path);
        $dimensions = @getimagesizefromstring($cover->bytes);

        return Image::create(
            path: $stored->getPath(),
            extension: $extension,
            mimeType: $cover->mimeType,
            size: $stored->getSize(),
            width: $dimensions !== false ? $dimensions[0] : 0,
            height: $dimensions !== false ? $dimensions[1] : 0,
            imageableType: 'album',
            albumId: $albumId,
        );
    }

    /** Removes the file of a cover the apply did not save. */
    public function discard(Image $image): void
    {
        try {
            $this->storage->delete($image->getPath());
        } catch (Throwable $exception) {
            $this->logger->error('Failed to remove the file of a match cover that was not saved', [
                'path' => $image->getPath(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
