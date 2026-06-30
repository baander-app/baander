<?php

declare(strict_types=1);

namespace App\Media\Application\CommandHandler;

use App\Media\Application\Command\PruneMissingImagesCommand;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Domain\Repository\ImageRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class PruneMissingImagesHandler
{
    public function __construct(
        private readonly ImageRepositoryInterface $imageRepository,
        private readonly StoragePortInterface $storage,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(PruneMissingImagesCommand $command): int
    {
        $total = $this->imageRepository->countAll();

        if ($total === 0) {
            $this->logger->info('No images to scan.');

            return 0;
        }

        $this->logger->info('Starting missing image prune', ['total' => $total]);

        $batchSize = 500;
        $cursor = null;
        $scanned = 0;
        $pruned = 0;

        while (true) {
            $images = $this->imageRepository->findAllAfter($cursor, $batchSize);

            if ($images === []) {
                break;
            }

            foreach ($images as $image) {
                ++$scanned;

                if (!$this->storage->exists($image->getPath())) {
                    ++$pruned;

                    $this->logger->warning('Pruning image record with missing file', [
                        'image_id' => $image->getId()->toString(),
                        'path' => $image->getPath(),
                        'imageable_type' => $image->getImageableType(),
                    ]);

                    $this->imageRepository->delete($image);
                }

                $cursor = $image->getId();
            }
        }

        $this->logger->info('Image prune completed', [
            'scanned' => $scanned,
            'pruned' => $pruned,
        ]);

        return $pruned;
    }
}
