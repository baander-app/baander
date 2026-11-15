<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Artist;

use App\Catalog\Application\Command\Artist\ChangeArtistCreditRoleCommand;
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
 * Changes the role of the artist's credit on a song or an album that the current role names, or
 * of its only credit there. Changing a credit to a role the artist already holds on the target
 * leaves the credit with that role and removes the changed one. On a song this is a hand-made change
 * to its credits, which no longer count as set by a match.
 *
 * The change runs in one transaction under the locks every catalog writer takes, so it waits for
 * a match apply, a retag or a merge that holds the same artist, song or album. It reads the
 * artist's roles only under those locks, decides on the roles that writer committed, and commits
 * the changed credit together with the song's record of it.
 */
final readonly class ChangeArtistCreditRoleHandler
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
     * @throws InvalidInputException when the public ID, the song or album ID, or a role is malformed,
     *                               or the current role is left out while the artist holds several
     * @throws NotFoundException     when the artist, the song or the album does not exist, or the
     *                               artist has no such credit
     * @throws ConflictException     when another change to the same artist, song or album ran at the
     *                               same time (`credit_busy`); nothing is written
     */
    #[AsMessageHandler]
    public function __invoke(ChangeArtistCreditRoleCommand $command): void
    {
        $publicId = CatalogInput::publicId($command->artistPublicId);
        $targetPublicId = ArtistCreditInput::publicTargetId($command->target, $command->targetId);
        $role = ArtistCreditInput::role($command->role);
        $named = $command->currentRole === null ? null : ArtistCreditInput::role($command->currentRole);
        $artist = ArtistCreditInput::artist($this->artists, $publicId);
        $targetId = ArtistCreditInput::targetId($command->target, $targetPublicId, $this->songs, $this->albums);

        ArtistCreditInput::inTransaction($this->transaction, function () use ($command, $role, $named, $artist, $targetId): void {
            $song = ArtistCreditInput::lock($command->target, $artist, $targetId, $command->targetId, $this->artists, $this->merges, $this->songs, $this->albums);
            $artistId = $artist->getId();

            $roles = match ($command->target) {
                CreditTarget::Song => $this->artists->songCreditRoles($artistId, $targetId),
                CreditTarget::Album => $this->artists->albumCreditRoles($artistId, $targetId),
            };
            if ($roles === []) {
                throw ArtistCreditInput::noCredit($command->artistPublicId, $command->target, $command->targetId);
            }

            if ($named !== null) {
                if (!in_array($named, $roles, true)) {
                    throw ArtistCreditInput::noCredit($command->artistPublicId, $command->target, $command->targetId, $named);
                }
                $current = $named;
            } elseif (count($roles) === 1) {
                $current = $roles[0];
            } else {
                throw new InvalidInputException(sprintf(
                    'Artist "%s" has several roles on %s "%s" (%s); name the role to change.',
                    $command->artistPublicId,
                    $command->target->value,
                    $command->targetId,
                    implode(', ', array_map(static fn (?string $held): string => $held ?? 'no role', $roles)),
                ));
            }

            $changed = match ($command->target) {
                CreditTarget::Song => $this->artists->updateSongRole($artistId, $targetId, $current, $role),
                CreditTarget::Album => $this->artists->updateAlbumRole($artistId, $targetId, $current, $role),
            };
            if (!$changed) {
                throw ArtistCreditInput::noCredit($command->artistPublicId, $command->target, $command->targetId, $current);
            }
            ArtistCreditInput::creditsChangedByHand($song, $this->songs);
        });
    }
}
