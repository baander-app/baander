<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\ImportIdentity;

use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gives an existing artist a MusicBrainz ID that a metadata search found. It locks the artist's
 * name and the ID with the keys an import locks before it adopts an ID onto a same-name artist or
 * creates an artist holding one, so a search and an import never both write the artist or the ID.
 * Search hits are checked against the artist's name because their ID would otherwise bind to an
 * artist that merely contains the hit's name, such as Hank Williams, Jr. for
 * "Post Malone, Hank Williams Jr.", and no command can take a stored ID back off.
 */
final readonly class ArtistMusicbrainzIdAssigner
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Writes the ID only when the artist has none, no other artist holds it, and $matchedName
     * equals the artist's name ignoring case. Call it inside a transaction: its locks hold until
     * that transaction ends, and {@see ImportIdentityLock} refuses to lock outside one.
     *
     * The write is a DQL statement, so an artist entity this unit of work manages keeps its
     * earlier ID until it is refreshed.
     */
    public function assign(Uuid $artistId, MusicbrainzId $id, string $matchedName): MusicbrainzIdAssignment
    {
        $before = $this->stored($artistId);
        if ($before === null) {
            return MusicbrainzIdAssignment::ArtistMissing;
        }

        ImportIdentityLock::acquire($this->entityManager->getConnection(), [
            ImportIdentityLock::artistNameKey($before['name']),
            ImportIdentityLock::artistMusicbrainzIdKey($id),
        ]);
        // A statement of its own after the locks, so under READ COMMITTED it reads what an import
        // that held them first committed.
        $artist = $this->stored($artistId);

        if ($artist === null) {
            return MusicbrainzIdAssignment::ArtistMissing;
        }
        if ($artist['mbid'] !== null) {
            return MusicbrainzIdAssignment::ArtistHasId;
        }
        // The name lock covers the name read before it, not a name the artist took since.
        if ($artist['name'] !== $before['name']) {
            return MusicbrainzIdAssignment::ArtistRenamed;
        }
        if (self::caseFolded($artist['name']) !== self::caseFolded($matchedName)) {
            return MusicbrainzIdAssignment::NameDiffers;
        }
        if ($this->isHeld($id)) {
            return MusicbrainzIdAssignment::HeldByAnotherArtist;
        }

        return $this->write($artistId, $id) ? MusicbrainzIdAssignment::Assigned : MusicbrainzIdAssignment::ArtistHasId;
    }

    /** @return array{name: string, mbid: ?string}|null */
    private function stored(Uuid $artistId): ?array
    {
        /** @var array{name: string, mbid: ?string}|null $artist */
        $artist = $this->entityManager
            ->createQuery('SELECT artist.name AS name, artist.mbid AS mbid FROM ' . ArtistEntity::class . ' artist WHERE artist.id = :id')
            ->setParameter('id', $artistId)
            ->getOneOrNullResult();

        return $artist;
    }

    private function isHeld(MusicbrainzId $id): bool
    {
        return (int) $this->entityManager
            ->createQuery('SELECT COUNT(artist.id) FROM ' . ArtistEntity::class . ' artist WHERE artist.mbid = :mbid')
            ->setParameter('mbid', $id->toString())
            ->getSingleScalarResult() > 0;
    }

    /**
     * One guarded statement rather than a flush of the entity: its predicate keeps a stored ID
     * even when a writer that does not take these locks gave the artist one since the read.
     */
    private function write(Uuid $artistId, MusicbrainzId $id): bool
    {
        return $this->entityManager
            ->createQuery(
                'UPDATE ' . ArtistEntity::class . ' artist SET artist.mbid = :mbid, artist.updatedAt = CURRENT_TIMESTAMP()'
                . ' WHERE artist.id = :id AND artist.mbid IS NULL',
            )
            ->setParameter('mbid', $id->toString())
            ->setParameter('id', $artistId)
            ->execute() === 1;
    }

    private static function caseFolded(string $name): string
    {
        return mb_convert_case($name, MB_CASE_FOLD, 'UTF-8');
    }
}
