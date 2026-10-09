<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Artist;

use App\Catalog\Application\Command\Artist\DeleteArtistCommand;
use App\Catalog\Application\Command\CatalogDeletionResult;
use App\Catalog\Application\CommandHandler\CatalogInput;
use App\Catalog\Application\CommandHandler\Cover\CoverImageDiscarder;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes an artist, then its cover image record, file and derived files. The image row keeps
 * no owner once the artist is gone (images.artist_id is ON DELETE SET NULL), so it is deleted
 * after the artist's delete has committed.
 */
final readonly class DeleteArtistHandler
{
    public function __construct(
        private ArtistPortInterface $artists,
        private CoverImageDiscarder $covers,
    ) {
    }

    /**
     * @throws InvalidInputException when the public ID is malformed
     * @throws NotFoundException     when no artist has the public ID
     */
    #[AsMessageHandler]
    public function __invoke(DeleteArtistCommand $command): CatalogDeletionResult
    {
        $artist = $this->artists->findByPublicId(CatalogInput::publicId($command->publicId))
            ?? throw new NotFoundException(sprintf('Artist "%s" not found.', $command->publicId));
        $coverImageId = $artist->getCoverImageId();

        $this->artists->delete($artist);

        if ($coverImageId !== null) {
            $this->covers->discard($coverImageId);
        }

        return CatalogDeletionResult::of(['artists' => 1, 'coverImages' => $coverImageId !== null ? 1 : 0]);
    }
}
