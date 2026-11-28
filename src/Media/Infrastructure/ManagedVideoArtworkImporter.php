<?php

declare(strict_types=1);

namespace App\Media\Infrastructure;

use App\Filesystem\Application\Port\MimeDetectorPortInterface;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Media\Application\DTO\PreparedVideoArtwork;
use App\Media\Application\Port\ImagePortInterface;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Application\Port\VideoArtworkImportInterface;
use App\Media\Domain\Model\Image;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Domain\Model\Uuid;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class ManagedVideoArtworkImporter implements VideoArtworkImportInterface
{
    private const int MAX_BYTES = 10 * 1024 * 1024;
    private const array EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private LibraryMediaFilesInterface $files,
        private StoragePortInterface $storage,
        private ImagePortInterface $images,
        private MimeDetectorPortInterface $mime,
        private LoggerInterface $logger,
    ) {}

    public function prepare(Uuid $libraryId, string $absolutePath): PreparedVideoArtwork
    {
        $contents = $this->files->readSidecar($libraryId, $absolutePath, self::MAX_BYTES);
        if ($contents === null || $contents === '') {
            throw new ServiceUnavailableException('Local video artwork is unavailable.');
        }
        $type = $this->mime->detectFromBytes(substr($contents, 0, 32));
        $extension = self::EXTENSIONS[$type] ?? null;
        $dimensions = @getimagesizefromstring($contents);
        if ($extension === null || $dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1) {
            throw new ServiceUnavailableException('Local video artwork is not a supported image.');
        }
        $prefix = 'images/video/' . $libraryId->toString() . '/' . hash('sha256', $contents) . '/';
        foreach ($this->images->findManagedVideoArtwork($libraryId, $prefix) as $existing) {
            if ($this->storage->exists($existing->getPath())) {
                return new PreparedVideoArtwork($existing, '/api/images/' . $existing->getPublicId()->toString() . '/file', newlyStored: false);
            }
        }
        $path = $prefix . Uuid::v7()->toString() . '.' . $extension;
        try {
            $stored = $this->storage->storeFromBytes($contents, $path);
        } catch (Throwable $exception) {
            $this->discardPath($path);
            throw new ServiceUnavailableException('Local video artwork could not be stored.', previous: $exception);
        }
        $image = Image::create(
            path: $stored->getPath(), extension: $extension, mimeType: $type,
            size: $stored->getSize(), width: $dimensions[0], height: $dimensions[1],
            imageableType: 'video', libraryId: $libraryId,
        );

        return new PreparedVideoArtwork($image, '/api/images/' . $image->getPublicId()->toString() . '/file');
    }

    public function persist(PreparedVideoArtwork $artwork): void
    {
        if ($artwork->newlyStored) {
            $this->images->save($artwork->image);
        }
    }

    public function discard(PreparedVideoArtwork $artwork): void
    {
        if (!$artwork->newlyStored) {
            return;
        }
        $this->discardPath($artwork->image->getPath());
    }

    private function discardPath(string $path): void
    {
        try {
            $this->storage->delete($path);
        } catch (Throwable $exception) {
            $this->logger->error('Failed to discard prepared video artwork.', ['path' => $path, 'error' => $exception->getMessage()]);
        }
    }
}
