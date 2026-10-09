<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Cover;

use App\Catalog\Application\Command\Cover\RemoveCoverCommand;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Clears an album's or artist's cover, then deletes the image record and its files once the
 * owner is saved.
 */
final readonly class RemoveCoverHandler
{
    public function __construct(
        private CoverOwners $owners,
        private CoverImageDiscarder $discarder,
    ) {
    }

    /**
     * @throws InvalidInputException when the public ID is malformed
     * @throws NotFoundException     when the owner does not exist or has no cover
     */
    #[AsMessageHandler]
    public function __invoke(RemoveCoverCommand $command): void
    {
        $owner = $this->owners->find($command->owner, $command->publicId);
        $imageId = $owner->getCoverImageId();
        if ($imageId === null) {
            throw new NotFoundException(sprintf('%s "%s" has no cover.', $command->owner->label(), $command->publicId));
        }

        $owner->setCoverImage(null);
        $this->owners->save($owner);

        $this->discarder->discard($imageId);
    }
}
