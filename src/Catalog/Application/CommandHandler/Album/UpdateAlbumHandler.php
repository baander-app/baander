<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Album;

use App\Catalog\Application\Command\Album\UpdateAlbumCommand;
use App\Catalog\Application\CommandHandler\CatalogInput;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Lifts the unlocked fields, applies the edit, then locks the newly locked fields, and saves the
 * album only when all of it is accepted. A field locked before the request refuses the edit; one
 * request may unlock a field and change it, or change a field and lock it.
 */
final readonly class UpdateAlbumHandler
{
    public function __construct(
        private AlbumPortInterface $albums,
    ) {
    }

    /**
     * @throws InvalidInputException when the public ID is malformed, a lock names an unknown field,
     *                               the edit changes a locked field, or a value is invalid
     * @throws NotFoundException     when no album has the public ID
     */
    #[AsMessageHandler]
    public function __invoke(UpdateAlbumCommand $command): Album
    {
        $album = $this->albums->findByPublicId(CatalogInput::publicId($command->publicId))
            ?? throw new NotFoundException(sprintf('Album "%s" not found.', $command->publicId));

        $locks = CatalogInput::lockChanges($album->getLockedFields(), $command->lockedFields, $command->lock, $command->unlock);

        try {
            foreach ($locks['unlock'] as $field) {
                $album->unlockField($field);
            }

            $album->updateMetadata(
                title: $command->title,
                type: $command->type,
                year: $command->year,
                label: $command->label,
                catalogNumber: $command->catalogNumber,
                barcode: $command->barcode,
                country: $command->country,
                language: $command->language,
                disambiguation: $command->disambiguation,
                annotation: $command->annotation,
            );
            foreach ($locks['lock'] as $field) {
                $album->lockField($field);
            }
        } catch (InvalidArgumentException $exception) {
            throw new InvalidInputException($exception->getMessage(), [], $exception);
        }

        $this->albums->save($album);

        return $album;
    }
}
