<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Artist;

use App\Catalog\Application\Command\Artist\DeleteArtistCommand;
use App\Catalog\Application\Command\CatalogDeletionResult;
use App\Catalog\Application\Service\CatalogInput;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes an artist with its credits, favorites and recommendations in one transaction, then its
 * cover image record, file and derived files once that has committed.
 */
final readonly class DeleteArtistHandler
{
    public function __construct(
        private ArtistPortInterface $artists,
    ) {
    }

    /**
     * @throws InvalidInputException when the public ID is malformed
     * @throws NotFoundException     when no artist has the public ID
     */
    #[AsMessageHandler]
    public function __invoke(DeleteArtistCommand $command): CatalogDeletionResult
    {
        $artist = CatalogInput::publicId($command->publicId)
            |> $this->artists->findByPublicId(...)
            ?? throw new NotFoundException('Artist "' . $command->publicId . '" not found.');

        $this->artists->delete($artist);

        return CatalogDeletionResult::of(['artists' => 1, 'coverImages' => $artist->getCoverImageId() !== null ? 1 : 0]);
    }
}
