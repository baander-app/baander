<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Genre;

use App\Catalog\Application\Command\Genre\UpdateGenreCommand;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Service\GenreParentResolver;
use App\Catalog\Domain\Model\Genre;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Changes a genre and keeps the genre hierarchy free of cycles.
 *
 * A new slug must be free. A new parent must be an existing genre that is neither the genre itself nor one of
 * its descendants. Nothing is saved when any change is rejected.
 */
final readonly class UpdateGenreHandler
{
    public const string CYCLE_MESSAGE = 'Cannot set parent: would create a circular reference.';

    public function __construct(
        private GenrePortInterface $genres,
        private GenreParentResolver $parents,
    ) {
    }

    /**
     * @throws NotFoundException     when no genre has the slug
     * @throws ConflictException     when another genre has the new slug
     * @throws InvalidInputException when a value is invalid or the parent would create a cycle
     */
    #[AsMessageHandler]
    public function __invoke(UpdateGenreCommand $command): Genre
    {
        $genre = $this->genres->findBySlug($command->slug);
        if ($genre === null) {
            throw new NotFoundException(sprintf('Genre "%s" not found.', $command->slug));
        }

        if ($command->newSlug !== null && $command->newSlug !== $genre->getSlug() && $this->genres->findBySlug($command->newSlug) !== null) {
            throw new ConflictException(sprintf('A genre with the slug "%s" already exists.', $command->newSlug));
        }

        try {
            if ($command->name !== null || $command->newSlug !== null) {
                $genre->update($command->name ?? $genre->getName(), $command->newSlug ?? $genre->getSlug());
            }

            if ($command->parentId !== null) {
                $parentId = $this->parents->resolve($command->parentId);
                if ($this->genres->isDescendantOf($genre->getId(), $parentId)) {
                    throw new InvalidInputException(self::CYCLE_MESSAGE);
                }
                $genre->setParentId($parentId);
            }

            if ($command->mbid !== null) {
                $genre->updateMbid($command->mbid);
            }
        } catch (InvalidArgumentException $exception) {
            throw new InvalidInputException($exception->getMessage(), [], $exception);
        }

        $this->genres->save($genre);

        return $genre;
    }
}
