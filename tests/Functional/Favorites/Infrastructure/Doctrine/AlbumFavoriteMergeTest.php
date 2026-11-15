<?php

declare(strict_types=1);

namespace App\Tests\Functional\Favorites\Infrastructure\Doctrine;

use App\Favorites\Infrastructure\Doctrine\AlbumFavoriteMerge;
use App\Favorites\Infrastructure\Doctrine\Entity\UserFavoriteEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;

/**
 * The album merge's favorites statements against the migrated schema, inside the test's
 * transaction as inside a merge's. Favorites name an album or song by its public ID and have no
 * foreign key to it, so no album or song rows are needed.
 */
final class AlbumFavoriteMergeTest extends TestCase
{
    private const string FAVORITED_AT = '2026-11-01 10:00:00.123456+00';
    private const string TARGET_FAVORITED_AT = '2026-11-08 18:30:00.654321+00';

    private PublicId $source;
    private PublicId $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = new PublicId();
        $this->target = new PublicId();
    }

    public function testAUserWhoFavoritedTheSourceOnlyHoldsAFavoriteOfTheTargetWithItsCreationTime(): void
    {
        $listener = $this->createTestUser()->getId();
        $favorite = $this->favorite($listener, 'album', $this->source->toString(), self::FAVORITED_AT);
        $publicId = $this->publicIdOf($favorite);

        $this->merge();

        $this->assertSame(
            [[$favorite->toString(), 'album', $this->target->toString(), self::FAVORITED_AT]],
            $this->favoritesOf($listener),
        );
        $this->assertSame($publicId, $this->publicIdOf($favorite), 'The favorite keeps its public ID.');
    }

    /**
     * A user who favorited both albums keeps the favorite of the target, with its own creation
     * time. A user who favorited the target only is left as before, and so are favorites of songs
     * and artists, even those that carry the source album's public ID as text.
     */
    public function testAUserWhoFavoritedBothHoldsOneFavoriteOfTheTargetAndOtherFavoritesAreUntouched(): void
    {
        $fanOfBoth = $this->createTestUser()->getId();
        $this->favorite($fanOfBoth, 'album', $this->source->toString(), self::FAVORITED_AT);
        $kept = $this->favorite($fanOfBoth, 'album', $this->target->toString(), self::TARGET_FAVORITED_AT);
        $song = $this->favorite($fanOfBoth, 'song', $this->source->toString(), self::FAVORITED_AT);
        $artist = $this->favorite($fanOfBoth, 'artist', $this->source->toString(), self::FAVORITED_AT);
        $targetFan = $this->createTestUser()->getId();
        $targetOnly = $this->favorite($targetFan, 'album', $this->target->toString(), self::TARGET_FAVORITED_AT);

        $this->merge();

        $this->assertSame(
            [
                [$kept->toString(), 'album', $this->target->toString(), self::TARGET_FAVORITED_AT],
                [$artist->toString(), 'artist', $this->source->toString(), self::FAVORITED_AT],
                [$song->toString(), 'song', $this->source->toString(), self::FAVORITED_AT],
            ],
            $this->favoritesOf($fanOfBoth),
        );
        $this->assertSame([[$targetOnly->toString(), 'album', $this->target->toString(), self::TARGET_FAVORITED_AT]], $this->favoritesOf($targetFan));
    }

    /**
     * The manager has loaded the favorite that moves, the one a user who favorited both loses and
     * a song favorite carrying the source's public ID as text. After the statement it reads the
     * moved favorite as naming the target and finds none of the lost one, the song favorite stays
     * loaded, and a flush leaves the rows as the statement wrote them.
     */
    public function testTheFavoritesTheManagerHoldsOfTheSourceLeaveIt(): void
    {
        $sourceFan = $this->createTestUser()->getId();
        $moved = $this->favorite($sourceFan, 'album', $this->source->toString(), self::FAVORITED_AT);
        $fanOfBoth = $this->createTestUser()->getId();
        $dropped = $this->favorite($fanOfBoth, 'album', $this->source->toString(), self::FAVORITED_AT);
        $kept = $this->favorite($fanOfBoth, 'album', $this->target->toString(), self::TARGET_FAVORITED_AT);
        $song = $this->favorite($fanOfBoth, 'song', $this->source->toString(), self::FAVORITED_AT);
        foreach ([$moved, $dropped] as $favorite) {
            self::assertSame($this->source->toString(), $this->entityManager->find(UserFavoriteEntity::class, $favorite)?->getEntityPublicId());
        }
        $songFavorite = $this->entityManager->find(UserFavoriteEntity::class, $song);
        self::assertInstanceOf(UserFavoriteEntity::class, $songFavorite);

        $this->merge();

        $this->assertSame($this->target->toString(), $this->entityManager->find(UserFavoriteEntity::class, $moved)?->getEntityPublicId());
        $this->assertNull($this->entityManager->find(UserFavoriteEntity::class, $dropped));
        $this->assertTrue($this->entityManager->contains($songFavorite), 'The song favorite is still the one the manager holds.');
        $this->entityManager->flush();
        $this->assertSame([[$moved->toString(), 'album', $this->target->toString(), self::FAVORITED_AT]], $this->favoritesOf($sourceFan));
        $this->assertSame(
            [
                [$kept->toString(), 'album', $this->target->toString(), self::TARGET_FAVORITED_AT],
                [$song->toString(), 'song', $this->source->toString(), self::FAVORITED_AT],
            ],
            $this->favoritesOf($fanOfBoth),
        );
    }

    /**
     * A user who favorited the source's copy of a target song only holds a favorite of the target's
     * song, the copy's own with its ID, public ID and creation time. A user who favorited both
     * keeps the favorite of the target's song. An album favorite carrying the copy's public ID as
     * text is left as it was.
     */
    public function testAFavoriteOfTheDuplicateNamesTheKeptSongAndAUserWhoFavoritedBothHoldsOne(): void
    {
        $duplicate = new PublicId();
        $kept = new PublicId();
        $copyFan = $this->createTestUser()->getId();
        $moved = $this->favorite($copyFan, 'song', $duplicate->toString(), self::FAVORITED_AT);
        $publicId = $this->publicIdOf($moved);
        $album = $this->favorite($copyFan, 'album', $duplicate->toString(), self::FAVORITED_AT);
        $fanOfBoth = $this->createTestUser()->getId();
        $this->favorite($fanOfBoth, 'song', $duplicate->toString(), self::FAVORITED_AT);
        $keptFavorite = $this->favorite($fanOfBoth, 'song', $kept->toString(), self::TARGET_FAVORITED_AT);

        new AlbumFavoriteMerge($this->entityManager)->foldSong($duplicate, $kept);

        $this->assertSame(
            [
                [$album->toString(), 'album', $duplicate->toString(), self::FAVORITED_AT],
                [$moved->toString(), 'song', $kept->toString(), self::FAVORITED_AT],
            ],
            $this->favoritesOf($copyFan),
        );
        $this->assertSame($publicId, $this->publicIdOf($moved), 'The favorite keeps its public ID.');
        $this->assertSame([[$keptFavorite->toString(), 'song', $kept->toString(), self::TARGET_FAVORITED_AT]], $this->favoritesOf($fanOfBoth));
    }

    /**
     * The manager has loaded a favorite of the copy. After the fold it reads it as naming the
     * target's song, and a flush leaves the row as the fold wrote it.
     */
    public function testTheFavoritesTheManagerHoldsOfTheDuplicateLeaveIt(): void
    {
        $duplicate = new PublicId();
        $kept = new PublicId();
        $listener = $this->createTestUser()->getId();
        $favorite = $this->favorite($listener, 'song', $duplicate->toString(), self::FAVORITED_AT);
        self::assertSame($duplicate->toString(), $this->entityManager->find(UserFavoriteEntity::class, $favorite)?->getEntityPublicId());

        new AlbumFavoriteMerge($this->entityManager)->foldSong($duplicate, $kept);

        $this->assertSame($kept->toString(), $this->entityManager->find(UserFavoriteEntity::class, $favorite)?->getEntityPublicId());
        $this->entityManager->flush();
        $this->assertSame([[$favorite->toString(), 'song', $kept->toString(), self::FAVORITED_AT]], $this->favoritesOf($listener));
    }

    private function merge(): void
    {
        new AlbumFavoriteMerge($this->entityManager)->merge($this->source, $this->target);
    }

    /** Stores the user's favorite as Favorites stores one, created and last updated at $createdAt. */
    private function favorite(Uuid $userId, string $type, string $entityPublicId, string $createdAt): Uuid
    {
        $id = Uuid::v7();
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO user_favorites (id, public_id, user_id, entity_type, entity_public_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id->toString(), (new PublicId())->toString(), $userId->toString(), $type, $entityPublicId, $createdAt, $createdAt],
        );

        return $id;
    }

    /** @return list<array{string, string, string, string}> ID, type, favorited public ID and creation time of each of the user's favorites */
    private function favoritesOf(Uuid $userId): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row[0], (string) $row[1], (string) $row[2], (string) $row[3]],
            $this->entityManager->getConnection()->fetchAllNumeric(
                'SELECT id, entity_type, entity_public_id, created_at FROM user_favorites WHERE user_id = ? ORDER BY entity_type, id',
                [$userId->toString()],
            ),
        );
    }

    private function publicIdOf(Uuid $favoriteId): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne('SELECT public_id FROM user_favorites WHERE id = ?', [$favoriteId->toString()]);
    }
}
