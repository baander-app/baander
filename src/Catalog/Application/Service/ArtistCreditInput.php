<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service;

use App\Catalog\Application\Command\Artist\CreditTarget;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistIdentity;
use App\Catalog\Application\Port\ArtistMergePortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\TransactionDeadlockException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;

/**
 * Input rules, messages and the locking step the artist credit use cases share.
 */
final class ArtistCreditInput
{
    /**
     * A credit change wrote nothing: the database ended it to break a deadlock with another catalog
     * writer, or its artist took another name or MusicBrainz ID while it waited for its locks.
     * Making the change again may succeed.
     */
    public const string BUSY_REASON = 'credit_busy';

    /**
     * @throws InvalidInputException when the value is not a public identifier
     */
    public static function targetId(
        CreditTarget $target,
        PublicId $publicId,
        SongPortInterface $songs,
        AlbumPortInterface $albums,
    ): Uuid
    {
        $entity = match ($target) {
            CreditTarget::Song => $songs->findByPublicId($publicId),
            CreditTarget::Album => $albums->findByPublicId($publicId),
        };
        return ($entity ?? throw self::targetNotFound($target, $publicId->toString()))->getId();
    }

    public static function publicTargetId(CreditTarget $target, string $value): PublicId
    {
        try {
            return PublicId::fromString($value);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidInputException(sprintf('Invalid %s ID format.', $target->value), [], $exception);
        }
    }

    /**
     * @return string the ArtistRole value
     *
     * @throws InvalidInputException when the value is no ArtistRole
     */
    public static function role(string $value): string
    {
        if (ArtistRole::tryFrom($value) === null) {
            throw new InvalidInputException(sprintf(
                'Invalid role "%s". Valid roles: %s.',
                $value,
                implode(', ', array_map(static fn (ArtistRole $role): string => $role->value, ArtistRole::cases())),
            ));
        }

        return $value;
    }

    /**
     * @throws NotFoundException when no artist has the public ID
     */
    public static function artist(ArtistPortInterface $artists, PublicId $publicId): Artist
    {
        return $artists->findByPublicId($publicId) ?? throw self::artistNotFound($publicId->toString());
    }

    /**
     * Runs a change to an artist's credits in one transaction, so the credit and what the change
     * records on its song are committed together or not at all.
     *
     * @template T
     *
     * @param callable(): T $change
     * @param-immediately-invoked-callable $change
     *
     * @return T
     *
     * @throws ConflictException when the database ended the change to break a deadlock with another
     *                           catalog writer (`credit_busy`); nothing is written
     */
    public static function inTransaction(TransactionPortInterface $transaction, callable $change): mixed
    {
        try {
            return $transaction->run($change);
        } catch (TransactionDeadlockException $deadlock) {
            throw self::busy($deadlock);
        }
    }

    /**
     * Takes the locks a change to the artist's credits on the song or album needs, inside the
     * caller's transaction and in the order every catalog writer takes its locks: an album's row,
     * the import keys of the artist's name and MusicBrainz ID, the artist's row, a song's row. A
     * match apply, a retag, an artist merge or removal and another credit change that hold one of
     * them finish first. The artist and the song or album are then read again, in statements of
     * their own that see what those writers committed.
     *
     * The credit's foreign key would lock the artist's row only at the insert, after the song's;
     * a match apply locks the artist before the song, so the change takes the artist first as well.
     *
     * @param Artist $artist         the artist as read before the transaction, whose name and ID give the keys
     * @param string $targetPublicId the song's or album's public ID, as given
     *
     * @return Song|null the song as it is under its lock, for the caller to change and save; null for an album
     *
     * @throws NotFoundException when the artist, the song or the album no longer exists
     * @throws ConflictException when the artist holds another name or MusicBrainz ID by then (`credit_busy`)
     * @throws \LogicException   outside a transaction, before taking any lock
     */
    public static function lock(
        CreditTarget $target,
        Artist $artist,
        Uuid $targetId,
        string $targetPublicId,
        ArtistPortInterface $artists,
        ArtistMergePortInterface $merges,
        SongPortInterface $songs,
        AlbumPortInterface $albums,
    ): ?Song {
        $artistId = $artist->getId();
        if ($target === CreditTarget::Album) {
            $albums->lockInIdOrder([$targetId]);
        }
        $songs->lockImportIdentities([self::storedIdentity($artist)], []);
        $merges->lock([$artistId]);
        if ($target === CreditTarget::Song) {
            $songs->lockInIdOrder([$targetId]);
        }

        $locked = $artists->findFreshByUuid($artistId) ?? throw self::artistNotFound($artist->getPublicId()->toString());
        $found = match ($target) {
            CreditTarget::Song => $songs->findFreshByUuid($targetId),
            CreditTarget::Album => $albums->findFreshByUuid($targetId),
        } ?? throw self::targetNotFound($target, $targetPublicId);
        // The keys cover the name and ID read before them. Taking the key of a name or ID the artist
        // took since, after its row, could close a cycle with an import that reaches the artist by it.
        if ($locked->getName() !== $artist->getName() || $locked->getMbid() !== $artist->getMbid()) {
            throw self::busy();
        }

        return $found instanceof Song ? $found : null;
    }

    /**
     * Records on a song whose credits a hand-made change touched that its credits are no longer the
     * ones a match set, and saves it. An album keeps no such record.
     *
     * @param Song|null $song the song as read under its lock; null for an album
     */
    public static function creditsChangedByHand(?Song $song, SongPortInterface $songs): void
    {
        if ($song?->creditsChangedByHand() === true) {
            $songs->save($song);
        }
    }

    public static function noCredit(string $publicId, CreditTarget $target, string $targetId, ?string $role = null): NotFoundException
    {
        return new NotFoundException($role === null
            ? sprintf('Artist "%s" has no credit on %s "%s".', $publicId, $target->value, $targetId)
            : sprintf('Artist "%s" has no "%s" credit on %s "%s".', $publicId, $role, $target->value, $targetId));
    }

    public static function targetNotFound(CreditTarget $target, string $publicId): NotFoundException
    {
        return new NotFoundException(sprintf('%s "%s" not found.', $target->label(), $publicId));
    }

    /** The identity an import names the artist by: its stored name, and its MusicBrainz ID when it holds one. */
    private static function storedIdentity(Artist $artist): ArtistIdentity
    {
        return new ArtistIdentity($artist->getName(), $artist->getMbid() === null ? [] : [$artist->getMbid()]);
    }

    private static function artistNotFound(string $publicId): NotFoundException
    {
        return new NotFoundException(sprintf('Artist "%s" not found.', $publicId));
    }

    private static function busy(?TransactionDeadlockException $deadlock = null): ConflictException
    {
        return new ConflictException(
            'Another change to the same artist, song or album ran at the same time. Make the change again.',
            ['reason' => self::BUSY_REASON],
            $deadlock,
        );
    }
}
