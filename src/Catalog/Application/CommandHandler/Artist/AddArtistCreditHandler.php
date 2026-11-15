<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Artist;

use App\Catalog\Application\Command\Artist\AddArtistCreditCommand;
use App\Catalog\Application\Command\Artist\CreditTarget;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistMergePortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Application\Service\ArtistCreditInput;
use App\Catalog\Application\Service\CatalogInput;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Credits an artist on a song or an album. A credit the artist already has stays a single credit.
 * A new credit on a song is a hand-made change to its credits, which no longer count as set by a
 * match.
 *
 * The change runs in one transaction under the locks every catalog writer takes, so it waits for
 * a match apply, a retag or a merge that holds the same artist, song or album, decides on what
 * that writer committed, and commits the credit together with the song's record of it.
 */
final readonly class AddArtistCreditHandler
{
    public function __construct(
        private ArtistPortInterface $artists,
        private SongPortInterface $songs,
        private AlbumPortInterface $albums,
        private ArtistMergePortInterface $merges,
        private TransactionPortInterface $transaction,
    ) {
    }

    /**
     * @throws InvalidInputException when the public ID, the song or album ID, or the role is malformed
     * @throws NotFoundException     when the artist, the song or the album does not exist
     * @throws ConflictException     when another change to the same artist, song or album ran at the
     *                               same time (`credit_busy`); nothing is written
     */
    #[AsMessageHandler]
    public function __invoke(AddArtistCreditCommand $command): void
    {
        $publicId = CatalogInput::publicId($command->artistPublicId);
        $targetPublicId = ArtistCreditInput::publicTargetId($command->target, $command->targetId);
        $role = ArtistCreditInput::role($command->role);
        $artist = ArtistCreditInput::artist($this->artists, $publicId);
        $targetId = ArtistCreditInput::targetId($command->target, $targetPublicId, $this->songs, $this->albums);

        ArtistCreditInput::inTransaction($this->transaction, function () use ($command, $role, $artist, $targetId): void {
            $song = ArtistCreditInput::lock($command->target, $artist, $targetId, $command->targetId, $this->artists, $this->merges, $this->songs, $this->albums);
            $artistId = $artist->getId();

            // Adding a credit the song holds already changes nothing a match set.
            $held = $song !== null && in_array($role, $this->artists->songCreditRoles($artistId, $targetId), true);
            $added = match ($command->target) {
                CreditTarget::Song => $this->artists->addSongToArtist($artistId, $targetId, $role),
                CreditTarget::Album => $this->artists->addAlbumToArtist($artistId, $targetId, $role),
            };
            if (!$added) {
                throw ArtistCreditInput::targetNotFound($command->target, $command->targetId);
            }
            if (!$held) {
                ArtistCreditInput::creditsChangedByHand($song, $this->songs);
            }
        });
    }
}
