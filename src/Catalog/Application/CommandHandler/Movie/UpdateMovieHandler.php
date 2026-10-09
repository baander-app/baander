<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Movie;

use App\Catalog\Application\Command\Movie\UpdateMovieCommand;
use App\Catalog\Application\CommandHandler\CatalogInput;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Domain\Model\Movie;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class UpdateMovieHandler
{
    public function __construct(
        private MoviePortInterface $movies,
    ) {
    }

    /**
     * @throws InvalidInputException when the public ID is malformed or the title is empty
     * @throws NotFoundException     when no movie has the public ID
     */
    #[AsMessageHandler]
    public function __invoke(UpdateMovieCommand $command): Movie
    {
        $movie = $this->movies->findByPublicId(CatalogInput::publicId($command->publicId))
            ?? throw new NotFoundException(sprintf('Movie "%s" not found.', $command->publicId));

        try {
            $movie->updateMetadata(
                title: $command->title,
                year: $command->year,
                summary: $command->summary,
            );
        } catch (InvalidArgumentException $exception) {
            throw new InvalidInputException($exception->getMessage(), [], $exception);
        }

        $this->movies->save($movie);

        return $movie;
    }
}
