<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Artist;

use App\Catalog\Application\Command\Artist\ChangeArtistCreditRoleCommand;
use App\Catalog\Application\Command\Artist\CreditTarget;
use App\Catalog\Application\Service\CatalogInput;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use App\Catalog\Application\Service\ArtistCreditInput;

/**
 * Changes the role of the artist's credit on a song or an album that the current role names, or
 * of its only credit there. Changing a credit to a role the artist already holds on the target
 * leaves the credit with that role and removes the changed one.
 */
final readonly class ChangeArtistCreditRoleHandler
{
    public function __construct(
        private ArtistPortInterface $artists,
    ) {
    }

    /**
     * @throws InvalidInputException when the public ID, the song or album ID, or a role is malformed,
     *                               or the current role is left out while the artist holds several
     * @throws NotFoundException     when the artist does not exist or has no such credit
     */
    #[AsMessageHandler]
    public function __invoke(ChangeArtistCreditRoleCommand $command): void
    {
        $publicId = CatalogInput::publicId($command->artistPublicId);
        $targetId = ArtistCreditInput::targetId($command->target, $command->targetId);
        $role = ArtistCreditInput::role($command->role);
        $named = $command->currentRole === null ? null : ArtistCreditInput::role($command->currentRole);
        $artistId = ArtistCreditInput::artist($this->artists, $publicId)->getId();

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
    }
}
