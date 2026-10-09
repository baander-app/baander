<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Artist;

use App\Catalog\Application\Command\Artist\CreditTarget;
use App\Catalog\Application\Command\Artist\RemoveArtistCreditCommand;
use App\Catalog\Application\CommandHandler\CatalogInput;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Removes every credit an artist has on a song or an album.
 */
final readonly class RemoveArtistCreditHandler
{
    public function __construct(
        private ArtistPortInterface $artists,
    ) {
    }

    /**
     * @throws InvalidInputException when the public ID or the song or album ID is malformed
     * @throws NotFoundException     when the artist does not exist or has no credit on the song or album
     */
    #[AsMessageHandler]
    public function __invoke(RemoveArtistCreditCommand $command): void
    {
        $publicId = CatalogInput::publicId($command->artistPublicId);
        $targetId = ArtistCreditInput::targetId($command->target, $command->targetId);
        $artistId = ArtistCreditInput::artist($this->artists, $publicId)->getId();

        $removed = match ($command->target) {
            CreditTarget::Song => $this->artists->removeSongFromArtist($artistId, $targetId),
            CreditTarget::Album => $this->artists->removeAlbumFromArtist($artistId, $targetId),
        };
        if (!$removed) {
            throw ArtistCreditInput::noCredit($command->artistPublicId, $command->target, $command->targetId);
        }
    }
}
