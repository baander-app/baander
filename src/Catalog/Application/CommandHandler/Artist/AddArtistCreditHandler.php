<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Artist;

use App\Catalog\Application\Command\Artist\AddArtistCreditCommand;
use App\Catalog\Application\Command\Artist\CreditTarget;
use App\Catalog\Application\Service\CatalogInput;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use App\Catalog\Application\Service\ArtistCreditInput;

/**
 * Credits an artist on a song or an album. A credit the artist already has stays a single credit.
 */
final readonly class AddArtistCreditHandler
{
    public function __construct(
        private ArtistPortInterface $artists,
    ) {
    }

    /**
     * @throws InvalidInputException when the public ID, the song or album ID, or the role is malformed
     * @throws NotFoundException     when the artist, the song or the album does not exist
     */
    #[AsMessageHandler]
    public function __invoke(AddArtistCreditCommand $command): void
    {
        $publicId = CatalogInput::publicId($command->artistPublicId);
        $targetId = ArtistCreditInput::targetId($command->target, $command->targetId);
        $role = ArtistCreditInput::role($command->role);
        $artistId = ArtistCreditInput::artist($this->artists, $publicId)->getId();

        $added = match ($command->target) {
            CreditTarget::Song => $this->artists->addSongToArtist($artistId, $targetId, $role),
            CreditTarget::Album => $this->artists->addAlbumToArtist($artistId, $targetId, $role),
        };
        if (!$added) {
            throw new NotFoundException(sprintf('%s "%s" not found.', $command->target->label(), $command->targetId));
        }
    }
}
