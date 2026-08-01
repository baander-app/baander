<?php

declare(strict_types=1);

namespace App\Metadata\Application\CommandHandler;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\MetadataContentReaderPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Media\Application\Port\ImagePortInterface;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Domain\Model\Image;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Metadata\Domain\Model\CoverArt;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class ExtractAlbumCoverHandler
{
    private const MAX_COVER_SIZE = 10 * 1024 * 1024; // 10 MB

    private const array SUPPORTED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly MetadataContentReaderPortInterface $metadataReader,
        private readonly SongPortInterface $songService,
        private readonly AlbumPortInterface $albumService,
        private readonly ImagePortInterface $imagePort,
        private readonly StoragePortInterface $storage,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler(fromTransport: 'async')]
    public function __invoke(ExtractAlbumCoverCommand $command): void
    {
        $album = $this->albumService->findByUuid($command->getAlbumId());

        if ($album === null) {
            $this->logger->warning('Album not found for cover extraction, skipping', [
                'album_id' => $command->getAlbumId()->toString(),
            ]);

            return;
        }

        if ($album->getCoverImageId() !== null) {
            $this->logger->debug('Album already has a cover image, skipping', [
                'album_id' => $album->getId()->toString(),
            ]);

            return;
        }

        $coverArt = $this->extractCover($album);

        if ($coverArt === null) {
            return;
        }

        if ($coverArt->getImageData() === '') {
            $this->logger->debug('Cover art has no image data, skipping', [
                'album_id' => $album->getId()->toString(),
            ]);

            return;
        }

        $mimeType = $coverArt->getMimeType();
        if (!isset(self::SUPPORTED_MIME_TYPES[$mimeType])) {
            $this->logger->warning('Cover art has unsupported MIME type, skipping', [
                'album_id' => $album->getId()->toString(),
                'mime_type' => $mimeType,
            ]);

            return;
        }

        if ($coverArt->getImageSize() > self::MAX_COVER_SIZE) {
            $this->logger->warning('Cover art exceeds maximum size, skipping', [
                'album_id' => $album->getId()->toString(),
                'size' => $coverArt->getImageSize(),
                'max_size' => self::MAX_COVER_SIZE,
            ]);

            return;
        }

        $extension = self::SUPPORTED_MIME_TYPES[$mimeType];
        $relativePath = 'images/album/' . $album->getId()->toString() . '.' . $extension;

        try {
            $storedFile = $this->storage->storeFromBytes($coverArt->getImageData(), $relativePath);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to store cover art file', [
                'album_id' => $album->getId()->toString(),
                'path' => $relativePath,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $image = Image::create(
            path: $storedFile->getPath(),
            extension: $extension,
            mimeType: $mimeType,
            size: $coverArt->getImageSize(),
            width: $coverArt->getWidth(),
            height: $coverArt->getHeight(),
            imageableType: 'album',
            albumId: $album->getId(),
        );

        $this->entityManager->beginTransaction();
        try {
            $this->imagePort->save($image);
            $album->setCoverImage($image->getId());
            $this->albumService->save($album);
            $this->entityManager->commit();

            $this->logger->info('Cover image extracted for album', [
                'album_id' => $album->getId()->toString(),
                'image_id' => $image->getId()->toString(),
                'path' => $storedFile->getPath(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();

            $this->storage->delete($storedFile->getPath());

            $this->logger->error('Failed to persist cover image for album', [
                'album_id' => $album->getId()->toString(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function extractCover(object $album): ?CoverArt
    {
        try {
            $songs = $this->songService->findByAlbum($album->getId(), limit: 1);
            if ($songs === []) {
                return null;
            }

            $path = $songs[0]->getPath();
            if (!file_exists($path) || !is_readable($path)) {
                return null;
            }

            $metadata = $this->metadataReader->readMetadata($path);

            if ($metadata === null) {
                return null;
            }

            return $metadata->getFrontCover();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to extract cover for album', [
                'album_id' => $album->getId()->toString(),
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
