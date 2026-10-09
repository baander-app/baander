<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Artist;

use App\Catalog\Application\Command\Artist\UpdateArtistCommand;
use App\Catalog\Application\Service\CatalogInput;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Domain\Model\Artist;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Lifts the unlocked fields, applies the edit, then locks the newly locked fields, and saves the
 * artist only when all of it is accepted. A field locked before the request refuses the edit; one
 * request may unlock a field and change it, or change a field and lock it.
 */
final readonly class UpdateArtistHandler
{
    public function __construct(
        private ArtistPortInterface $artists,
    ) {
    }

    /**
     * @throws InvalidInputException when the public ID is malformed, a lock names an unknown field,
     *                               the edit changes a locked field, or a value is invalid
     * @throws NotFoundException     when no artist has the public ID
     */
    #[AsMessageHandler]
    public function __invoke(UpdateArtistCommand $command): Artist
    {
        $artist = $this->artists->findByPublicId(CatalogInput::publicId($command->publicId))
            ?? throw new NotFoundException(sprintf('Artist "%s" not found.', $command->publicId));

        $locks = CatalogInput::lockChanges($artist->getLockedFields(), $command->lockedFields, $command->lock, $command->unlock);

        try {
            foreach ($locks['unlock'] as $field) {
                $artist->unlockField($field);
            }

            $artist->updateMetadata(
                name: $command->name,
                country: $command->country,
                gender: $command->gender,
                type: $command->type,
                disambiguation: $command->disambiguation,
                sortName: $command->sortName,
                biography: $command->biography,
            );
            foreach ($locks['lock'] as $field) {
                $artist->lockField($field);
            }
        } catch (InvalidArgumentException $exception) {
            throw new InvalidInputException($exception->getMessage(), [], $exception);
        }

        $this->artists->save($artist);

        return $artist;
    }
}
