<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Movie;

use App\Catalog\Application\Command\CatalogDeletionResult;
use App\Catalog\Application\Command\Movie\DeleteMovieCommand;
use App\Catalog\Application\CommandHandler\CatalogInput;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes a movie, then, in the same transaction, those of its videos that no other movie uses.
 */
final readonly class DeleteMovieHandler
{
    public function __construct(
        private MoviePortInterface $movies,
        private VideoRepositoryInterface $videos,
        private TransactionPortInterface $transaction,
    ) {
    }

    /**
     * @throws InvalidInputException when the public ID is malformed
     * @throws NotFoundException     when no movie has the public ID
     */
    #[AsMessageHandler]
    public function __invoke(DeleteMovieCommand $command): CatalogDeletionResult
    {
        $movie = $this->movies->findByPublicId(CatalogInput::publicId($command->publicId))
            ?? throw new NotFoundException(sprintf('Movie "%s" not found.', $command->publicId));
        $videoIds = array_map(static fn (string $id): Uuid => Uuid::fromString($id), array_values($movie->getVideoIds()));

        $videos = $this->transaction->run(function () use ($movie, $videoIds): int {
            $this->movies->delete($movie);

            return $this->videos->deleteUnlinked($videoIds);
        });

        return CatalogDeletionResult::of(['movies' => 1, 'videos' => $videos]);
    }
}
