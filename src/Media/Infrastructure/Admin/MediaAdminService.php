<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Admin;

use App\Media\Application\Command\PruneMissingImagesCommand;
use App\Media\Application\Port\MediaAdminPortInterface;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Domain\Repository\ImageRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class MediaAdminService implements MediaAdminPortInterface
{
    public function __construct(
        private readonly ImageRepositoryInterface $imageRepository,
        private readonly StoragePortInterface $storage,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getStorageStats(): array
    {
        $conn = $this->entityManager->getConnection();

        $totalRow = $conn->fetchAssociative('SELECT COUNT(*) as count, COALESCE(SUM(size), 0) as size FROM images');

        $byTypeRows = $conn->fetchAllAssociative(
            'SELECT imageable_type as type, COUNT(*) as count, COALESCE(SUM(size), 0) as size FROM images GROUP BY imageable_type ORDER BY count DESC',
        );

        return [
            'totalImages' => (int) ($totalRow['count'] ?? 0),
            'totalSize' => (int) ($totalRow['size'] ?? 0),
            'byType' => array_map(fn (array $row) => [
                'type' => $row['type'],
                'count' => (int) $row['count'],
                'size' => (int) $row['size'],
            ], $byTypeRows),
        ];
    }

    public function pruneMissingImages(): array
    {
        $this->logger->info('Prune missing images dispatched via admin');

        $this->bus->dispatch(new PruneMissingImagesCommand());

        return [
            'dispatched' => true,
        ];
    }

    public function checkMissingImages(): array
    {
        $totalImages = $this->imageRepository->countAll();

        $batchSize = 500;
        $cursor = null;
        $missingCount = 0;
        /** @var array<array{id: string, path: string, type: string}> $missingImages */
        $missingImages = [];

        while (true) {
            $images = $this->imageRepository->findAllAfter($cursor, $batchSize);

            if ($images === []) {
                break;
            }

            foreach ($images as $image) {
                if (!$this->storage->exists($image->getPath())) {
                    ++$missingCount;
                    $missingImages[] = [
                        'id' => $image->getId()->toString(),
                        'path' => $image->getPath(),
                        'type' => $image->getImageableType(),
                    ];
                }

                $cursor = $image->getId();
            }
        }

        return [
            'totalImages' => $totalImages,
            'missingCount' => $missingCount,
            'missingImages' => $missingImages,
        ];
    }
}
