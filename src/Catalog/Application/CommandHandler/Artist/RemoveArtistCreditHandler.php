<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Artist;

use App\Catalog\Application\Command\Artist\CreditTarget;
use App\Catalog\Application\Command\Artist\RemoveArtistCreditCommand;
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
 * Removes every credit an artist has on a song or an album. On a song this is a hand-made change to
 * its credits, which no longer count as set by a match.
 *
 * The change runs in one transaction under the locks every catalog writer takes, so it waits for
 * a match apply, a retag or a merge that holds the same artist, song or album, removes the credits
 * that writer committed, and commits the removal together with the song's record of it.
 */
final readonly class RemoveArtistCreditHandler
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
     * @throws InvalidInputException when the public ID or the song or album ID is malformed
     * @throws NotFoundException     when the artist, the song or the album does not exist, or the
     *                               artist has no credit on the song or album
     * @throws ConflictException     when another change to the same artist, song or album ran at the
     *                               same time (`credit_busy`); nothing is written
     */
    #[AsMessageHandler]
    public function __invoke(RemoveArtistCreditCommand $command): void
    {
        $publicId = CatalogInput::publicId($command->artistPublicId);
        $targetPublicId = ArtistCreditInput::publicTargetId($command->target, $command->targetId);
        $artist = ArtistCreditInput::artist($this->artists, $publicId);
        $targetId = ArtistCreditInput::targetId($command->target, $targetPublicId, $this->songs, $this->albums);

        ArtistCreditInput::inTransaction($this->transaction, function () use ($command, $artist, $targetId): void {
            $song = ArtistCreditInput::lock($command->target, $artist, $targetId, $command->targetId, $this->artists, $this->merges, $this->songs, $this->albums);

            $removed = match ($command->target) {
                CreditTarget::Song => $this->artists->removeSongFromArtist($artist->getId(), $targetId),
                CreditTarget::Album => $this->artists->removeAlbumFromArtist($artist->getId(), $targetId),
            };
            if (!$removed) {
                throw ArtistCreditInput::noCredit($command->artistPublicId, $command->target, $command->targetId);
            }
            ArtistCreditInput::creditsChangedByHand($song, $this->songs);
        });
    }
}
