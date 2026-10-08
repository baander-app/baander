<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Genre;

use App\Catalog\Application\Command\Genre\CreateGenreCommand;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Service\GenreParentResolver;
use App\Catalog\Domain\Model\Genre;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Creates a genre. Its slug must be free, and a parent must be an existing genre.
 */
final readonly class CreateGenreHandler
{
    public function __construct(
        private GenrePortInterface $genres,
        private GenreParentResolver $parents,
    ) {
    }

    /**
     * @throws InvalidInputException when the name, slug, MusicBrainz ID or parent is invalid
     * @throws ConflictException     when another genre has the slug
     */
    #[AsMessageHandler]
    public function __invoke(CreateGenreCommand $command): Genre
    {
        if ($this->genres->findBySlug($command->slug) !== null) {
            throw new ConflictException(sprintf('A genre with the slug "%s" already exists.', $command->slug));
        }

        $parent = $command->parentId !== null ? $this->parents->resolve($command->parentId) : null;

        try {
            $genre = Genre::create(
                name: $command->name,
                slug: $command->slug,
                parent: $parent,
                mbid: $command->mbid,
            );
        } catch (InvalidArgumentException $exception) {
            throw new InvalidInputException($exception->getMessage(), [], $exception);
        }

        $this->genres->save($genre);

        return $genre;
    }
}
