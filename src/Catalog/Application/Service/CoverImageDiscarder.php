<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service;

use App\Media\Application\Port\ImagePortInterface;
use App\Media\Application\Port\StoragePortInterface;
use App\Shared\Domain\Model\Uuid;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes a cover image that its owner no longer uses: the image record, then its file and
 * derived files.
 *
 * Call it only after the transaction that detached the image from its owner, or deleted the
 * owner, has committed; before that, a rollback would leave the owner pointing at a deleted
 * image. The committed change stands either way, so a failure here is logged, not thrown; the
 * leftover record or file has no owner.
 */
final readonly class CoverImageDiscarder
{
    public function __construct(
        private ImagePortInterface $images,
        private StoragePortInterface $storage,
        private LoggerInterface $logger,
    ) {
    }

    public function discard(Uuid $imageId): void
    {
        try {
            $image = $this->images->findByUuid($imageId);
            if ($image === null) {
                return;
            }

            $this->images->delete($image);
            $this->storage->delete($image->getPath());
            $this->storage->deleteDerived($image->getPath(), $image->getExtension());
        } catch (Throwable $exception) {
            $this->logger->error('Failed to delete a cover image that its owner no longer uses', [
                'image_id' => $imageId->toString(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
