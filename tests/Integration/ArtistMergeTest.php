<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Activity\Infrastructure\Doctrine\Entity\MediaActivityEntity;
use App\Catalog\Application\Port\ArtistMergePortInterface;
use App\Catalog\Application\Port\ArtistRemoval;
use App\Catalog\Application\Service\CoverImageDiscarder;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Infrastructure\Doctrine\Entity\ImageEntity;
use App\Media\Infrastructure\Storage\FlysystemStorage;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Support\FinalSchemaBootstrap;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Merges and removes artists through the container's merge port, in transactions, against the
 * migrated PostgreSQL schema: credits, favorites, play history, recommendations and the cover
 * follow the artist that survives, and a removed artist leaves no favorite or recommendation
 * behind. The cover files live in a storage directory of the test's own.
 */
final class ArtistMergeTest extends KernelTestCase
{
    private ?string $schema = null;
    private string $searchPath;
    private string $storageRoot;
    private string $albumId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->isolateSchema();
        $this->storageRoot = sys_get_temp_dir() . '/baander-artist-merge-' . (random_bytes(4) |> bin2hex(...));
        static::getContainer()->set(StoragePortInterface::class, new FlysystemStorage($this->storageRoot));
        $this->albumId = $this->storeAlbum();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->storageRoot);
        if ($this->schema !== null) {
            $connection = $this->connection();
            $connection->executeStatement('SET search_path TO ' . $this->searchPath);
            $connection->executeStatement('DROP SCHEMA ' . $connection->quoteIdentifier($this->schema) . ' CASCADE');
        }
        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testMergingHeadHunterzIntoHeadhunterzMovesItsCreditsFavoritesPlayHistoryAndCoverAndLeavesOneArtist(): void
    {
        [$source, $sourcePublicId] = $this->storeArtist('HeadHunterz');
        $cover = $this->storeCover($source);
        [$target, $targetPublicId] = $this->storeArtist('Headhunterz', 'b0b7a6d3-1b8a-4d38-a0a0-4c5d5fb7d9f2');
        [$brennanHeart] = $this->storeArtist('Brennan Heart');
        $song = $this->storeSong([[$source, 'primary', 'HeadHunterz']]);
        $this->storeAlbumCredit($source, 'primary', 'HeadHunterz');
        $listener = $this->storeUser();
        $this->storeFavorite($listener, $sourcePublicId);
        $activity = $this->storeActivity($listener, $song, $source);
        $recommendation = $this->storeRecommendation('artist', $source, 'artist', $brennanHeart, $listener);

        $removal = $this->merge($source, $target);

        self::assertSame([], $removal->imagesToDiscard, 'The target had no cover, so it took the source\'s.');
        self::assertSame([[$target, 'Headhunterz']], $this->artistsNamedLike('headhunterz'));
        self::assertSame([['primary', 'HeadHunterz', $target]], $this->songCredits($song), 'The credit keeps the name the song shows.');
        self::assertSame([[$target, 'primary', 'HeadHunterz']], $this->albumCredits());
        self::assertSame([$targetPublicId], $this->artistFavoritesOf($listener));
        self::assertSame($target, $this->connection()->fetchOne('SELECT artist_id::text FROM media_activities WHERE id = ?', [$activity]));
        self::assertSame([$target, $brennanHeart], $this->recommendationEnds($recommendation));
        self::assertSame($cover['id'], $this->coverOf($target));
        $this->storage()->exists($cover['path']) |> self::assertTrue(...);
    }

    public function testASongCreditedToBothArtistsEndsWithOneCreditToTheTargetUnderTheStrongerRole(): void
    {
        [$source] = $this->storeArtist('HeadHunterz');
        [$target] = $this->storeArtist('Headhunterz');
        $bothPrimary = $this->storeSong([[$source, 'primary', 'HeadHunterz'], [$target, 'primary', 'Headhunterz']]);
        $sourcePrimary = $this->storeSong([[$source, 'primary', 'HeadHunterz'], [$target, 'featured', 'Headhunterz']]);
        $targetPrimary = $this->storeSong([[$source, 'featured', 'HeadHunterz'], [$source, 'producer', 'HeadHunterz'], [$target, 'primary', 'Headhunterz']]);

        $removal = $this->merge($source, $target);

        self::assertSame([], $removal->imagesToDiscard);
        self::assertSame([['primary', 'Headhunterz', $target]], $this->songCredits($bothPrimary));
        self::assertSame([['primary', 'HeadHunterz', $target]], $this->songCredits($sourcePrimary), 'The target is primary under the name the song showed.');
        self::assertSame(
            [['primary', 'Headhunterz', $target], ['producer', 'HeadHunterz', $target]],
            $this->songCredits($targetPrimary),
            'A role other than primary or featured is a credit of its own.',
        );
    }

    public function testAUserWhoFavoritedBothArtistsEndsWithOneFavoriteOfTheTarget(): void
    {
        [$source, $sourcePublicId] = $this->storeArtist('HeadHunterz');
        [$target, $targetPublicId] = $this->storeArtist('Headhunterz');
        $both = $this->storeUser();
        $sourceOnly = $this->storeUser();
        $this->storeFavorite($both, $sourcePublicId);
        $this->storeFavorite($both, $targetPublicId);
        $this->storeFavorite($sourceOnly, $sourcePublicId);

        $removal = $this->merge($source, $target);

        self::assertSame([], $removal->imagesToDiscard);
        self::assertSame([$targetPublicId], $this->artistFavoritesOf($both));
        self::assertSame([$targetPublicId], $this->artistFavoritesOf($sourceOnly));
    }

    public function testATargetWithACoverKeepsItAndTheSourcesCoverIsLeftForDiscarding(): void
    {
        [$source] = $this->storeArtist('HeadHunterz');
        $sourceCover = $this->storeCover($source);
        [$target] = $this->storeArtist('Headhunterz');
        $targetCover = $this->storeCover($target);

        $removal = $this->merge($source, $target);

        self::assertSame([$sourceCover['id']], $this->ids($removal));
        self::assertSame($targetCover['id'], $this->coverOf($target));
    }

    /**
     * An apply holds the artists, their credits, their play history and the cover image it read.
     * The database deletes or repoints those rows under the merge, so the second merge and the
     * flushes after each must not trip over what the first left in the unit of work.
     */
    public function testTwoMergesInOneProcessBothSucceedWhileTheirEntitiesAreLoaded(): void
    {
        [$first] = $this->storeArtist('HeadHunterz');
        $cover = $this->storeCover($first);
        [$second] = $this->storeArtist('Head Hunterz');
        [$target] = $this->storeArtist('Headhunterz');
        $firstSong = $this->storeSong([[$first, 'primary', 'HeadHunterz']]);
        $secondSong = $this->storeSong([[$second, 'primary', 'Head Hunterz']]);
        $listener = $this->storeUser();
        $firstActivity = $this->storeActivity($listener, $firstSong, $first);
        $secondActivity = $this->storeActivity($listener, $secondSong, $second);

        $manager = $this->entityManager();
        foreach ([$first, $second, $target] as $artistId) {
            self::assertInstanceOf(ArtistEntity::class, $manager->find(ArtistEntity::class, Uuid::fromString($artistId)));
        }
        self::assertCount(2, $manager->getRepository(ArtistSongEntity::class)->findBy(['artist' => [Uuid::fromString($first), Uuid::fromString($second)]]));
        foreach ([$firstActivity, $secondActivity] as $activityId) {
            self::assertInstanceOf(MediaActivityEntity::class, $manager->find(MediaActivityEntity::class, Uuid::fromString($activityId)));
        }
        $coverImage = $manager->find(ArtistEntity::class, Uuid::fromString($first))?->getCoverImage();
        self::assertInstanceOf(ImageEntity::class, $coverImage);
        self::assertSame($cover['path'], $coverImage->getPath(), 'The cover image is loaded and holds its artist.');

        $firstRemoval = $this->merge($first, $target);
        $this->setCountry($target, 'NL');
        $secondRemoval = $this->merge($second, $target);
        $this->setCountry($target, 'DE');

        self::assertSame([], $this->ids($firstRemoval));
        self::assertSame([], $this->ids($secondRemoval));
        self::assertSame([$target], $this->artistIds());
        self::assertNull($manager->find(ArtistEntity::class, Uuid::fromString($first)), 'The merged artist left the unit of work.');
        foreach ([$firstSong, $secondSong] as $songId) {
            $credits = $manager->getRepository(ArtistSongEntity::class)->findBy(['song' => Uuid::fromString($songId)]);
            self::assertCount(1, $credits);
            self::assertSame($target, $credits[0]->getArtist()->getId()->toString(), 'The credit the unit of work returns is the moved one.');
        }
        foreach ([$firstActivity, $secondActivity] as $activityId) {
            self::assertSame($target, $manager->find(MediaActivityEntity::class, Uuid::fromString($activityId))?->getArtist()?->getId()->toString());
        }
        self::assertNull($coverImage->getArtist(), 'The loaded cover image was read again after its artist was deleted.');
        self::assertSame($cover['id'], $manager->find(ArtistEntity::class, Uuid::fromString($target))?->getCoverImage()?->getId()->toString());
        self::assertSame('DE', $this->connection()->fetchOne('SELECT country FROM artists WHERE id = ?', [$target]));
    }

    public function testRemovingAnArtistWithoutCreditsLeavesItsCoverForAfterTheCommitAndDeletesItsFavoritesAndRecommendations(): void
    {
        [$artist, $publicId] = $this->storeArtist('Post Malone, Luke Combs');
        $cover = $this->storeCover($artist);
        [$postMalone] = $this->storeArtist('Post Malone');
        $listener = $this->storeUser();
        $this->storeFavorite($listener, $publicId);
        $this->storeRecommendation('artist', $artist, 'artist', $postMalone, $listener);
        $this->storeRecommendation('artist', $postMalone, 'artist', $artist, null);
        $kept = $this->storeRecommendation('artist', $postMalone, 'song', $this->storeSong([]), null);
        $merges = static::getContainer()->get(ArtistMergePortInterface::class);

        $removal = static::getContainer()->get(TransactionPortInterface::class)->run(
            static function () use ($merges, $artist): ArtistRemoval {
                self::assertSame([$artist => false], $merges->lock([Uuid::fromString($artist)]));

                return Uuid::fromString($artist) |> $merges->remove(...);
            },
        );

        self::assertSame([$cover['id']], $this->ids($removal));
        self::assertTrue($this->storage()->exists($cover['path']), 'The removal leaves the file to the caller, for after the commit.');
        foreach ($removal->imagesToDiscard as $imageId) {
            static::getContainer()->get(CoverImageDiscarder::class)->discard($imageId);
        }

        $this->storage()->exists($cover['path']) |> self::assertFalse(...);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM images'));
        self::assertSame([$postMalone], $this->artistIds());
        self::assertSame([], $this->artistFavoritesOf($listener));
        self::assertSame([$kept], $this->recommendationIds());
    }

    public function testDeletingAnArtistThroughTheConsoleClearsItsFavoritesAndRecommendations(): void
    {
        [$artist, $publicId] = $this->storeArtist('Headhunterz');
        $cover = $this->storeCover($artist);
        [$brennanHeart] = $this->storeArtist('Brennan Heart');
        $song = $this->storeSong([[$artist, 'primary', 'Headhunterz']]);
        $listener = $this->storeUser();
        $this->storeFavorite($listener, $publicId);
        $activity = $this->storeActivity($listener, $song, $artist);
        $this->storeRecommendation('artist', $artist, 'artist', $brennanHeart, $listener);
        $this->storeRecommendation('artist', $brennanHeart, 'artist', $artist, null);

        $command = new Application(self::$kernel)->find('app:artist:delete');
        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute(['public-id' => $publicId, '--force' => true]), $tester->getDisplay());

        self::assertSame([$brennanHeart], $this->artistIds());
        self::assertSame([], $this->songCredits($song));
        self::assertSame([], $this->artistFavoritesOf($listener));
        self::assertSame([], $this->recommendationIds());
        self::assertSame(
            [$song, null],
            $this->connection()->fetchNumeric('SELECT song_id::text, artist_id::text FROM media_activities WHERE id = ?', [$activity]),
            'The play history keeps its song.',
        );
        $this->storage()->exists($cover['path']) |> self::assertFalse(...);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM images'));
    }

    private function merge(string $source, string $target): ArtistRemoval
    {
        $merges = static::getContainer()->get(ArtistMergePortInterface::class);

        return static::getContainer()->get(TransactionPortInterface::class)->run(
            static function () use ($merges, $source, $target): ArtistRemoval {
                $merges->lock([Uuid::fromString($source), Uuid::fromString($target)]);

                return $merges->merge(Uuid::fromString($source), Uuid::fromString($target));
            },
        );
    }

    /** Changes the artist through the unit of work, so its flush walks every entity the manager holds. */
    private function setCountry(string $artistId, string $country): void
    {
        $manager = $this->entityManager();
        $artist = $manager->find(ArtistEntity::class, Uuid::fromString($artistId));
        self::assertNotNull($artist);
        $artist->setCountry($country);
        $manager->flush();
    }

    /** @return list<string> */
    private function ids(ArtistRemoval $removal): array
    {
        return array_map(static fn (Uuid $id): string => $id->toString(), $removal->imagesToDiscard);
    }

    private function storeAlbum(): string
    {
        $libraryId = (new Uuid())->toString();
        $slug = 'artist-merge-' . (random_bytes(4) |> bin2hex(...));
        $this->connection()->executeStatement(
            "INSERT INTO libraries (id, public_id, name, slug, path, type, created_at, updated_at) VALUES (?, ?, 'Hardstyle', ?, ?, 'music', clock_timestamp(), clock_timestamp())",
            [$libraryId, (new PublicId())->toString(), $slug, '/' . $slug],
        );
        $albumId = (new Uuid())->toString();
        $this->connection()->executeStatement(
            "INSERT INTO albums (id, public_id, library_id, title, type, created_at, updated_at) VALUES (?, ?, ?, 'Defqon.1 2026', 'album', clock_timestamp(), clock_timestamp())",
            [$albumId, (new PublicId())->toString(), $libraryId],
        );

        return $albumId;
    }

    /** @return array{string, string} the artist's ID and public ID */
    private function storeArtist(string $name, ?string $mbid = null): array
    {
        $id = (new Uuid())->toString();
        $publicId = (new PublicId())->toString();
        $this->connection()->executeStatement(
            'INSERT INTO artists (id, public_id, name, mbid, created_at, updated_at) VALUES (?, ?, ?, ?, clock_timestamp(), clock_timestamp())',
            [$id, $publicId, $name, $mbid],
        );

        return [$id, $publicId];
    }

    /** @return array{id: string, path: string} a cover image stored for the artist and set as its cover */
    private function storeCover(string $artistId): array
    {
        $path = 'images/artists/' . $artistId . '.jpg';
        $this->storage()->storeFromBytes('cover', $path);
        $id = (new Uuid())->toString();
        $this->connection()->executeStatement(
            "INSERT INTO images (id, public_id, path, extension, mime_type, size, width, height, imageable_type, artist_id, created_at, updated_at) VALUES (?, ?, ?, 'jpg', 'image/jpeg', 5, 1, 1, 'artist', ?, clock_timestamp(), clock_timestamp())",
            [$id, (new PublicId())->toString(), $path, $artistId],
        );
        $this->connection()->executeStatement('UPDATE artists SET cover_image_id = ? WHERE id = ?', [$id, $artistId]);

        return ['id' => $id, 'path' => $path];
    }

    /** @param list<array{0: string, 1: string, 2: string}> $credits artist ID, role and credited name */
    private function storeSong(array $credits): string
    {
        $id = (new Uuid())->toString();
        $this->connection()->executeStatement(
            "INSERT INTO songs (id, public_id, album_id, title, path, size, mime_type, created_at, updated_at) VALUES (?, ?, ?, 'The Power Of The Mind', ?, 1000000, 'audio/flac', clock_timestamp(), clock_timestamp())",
            [$id, (new PublicId())->toString(), $this->albumId, '/music/' . $id . '.flac'],
        );
        foreach ($credits as [$artistId, $role, $creditedName]) {
            $this->connection()->executeStatement(
                'INSERT INTO artist_song (id, artist_id, song_id, role, credited_name) VALUES (?, ?, ?, ?, ?)',
                [(new Uuid())->toString(), $artistId, $id, $role, $creditedName],
            );
        }

        return $id;
    }

    private function storeAlbumCredit(string $artistId, string $role, string $creditedName): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO artist_album (id, artist_id, album_id, role, credited_name) VALUES (?, ?, ?, ?, ?)',
            [(new Uuid())->toString(), $artistId, $this->albumId, $role, $creditedName],
        );
    }

    private function storeUser(): string
    {
        $id = (new Uuid())->toString();
        $this->connection()->executeStatement(
            "INSERT INTO users (id, public_id, name, email, password, created_at, updated_at) VALUES (?, ?, 'Listener', ?, 'hash', clock_timestamp(), clock_timestamp())",
            [$id, (new PublicId())->toString(), 'listener-' . $id . '@baander.app'],
        );

        return $id;
    }

    private function storeFavorite(string $userId, string $artistPublicId): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO user_favorites (id, public_id, user_id, entity_type, entity_public_id, created_at, updated_at) VALUES (?, ?, ?, 'artist', ?, clock_timestamp(), clock_timestamp())",
            [(new Uuid())->toString(), (new PublicId())->toString(), $userId, $artistPublicId],
        );
    }

    private function storeActivity(string $userId, string $songId, string $artistId): string
    {
        $id = (new Uuid())->toString();
        $this->connection()->executeStatement(
            "INSERT INTO media_activities (id, public_id, user_id, activity_type, song_id, album_id, artist_id, play_count, love, created_at, updated_at) VALUES (?, ?, ?, 'play', ?, ?, ?, 3, false, clock_timestamp(), clock_timestamp())",
            [$id, (new PublicId())->toString(), $userId, $songId, $this->albumId, $artistId],
        );

        return $id;
    }

    private function storeRecommendation(string $sourceType, string $sourceId, string $targetType, string $targetId, ?string $userId): string
    {
        $id = (new Uuid())->toString();
        $this->connection()->executeStatement(
            'INSERT INTO recommendations (id, public_id, user_id, source_type, source_id, target_type, target_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, clock_timestamp(), clock_timestamp())',
            [$id, (new PublicId())->toString(), $userId, $sourceType, $sourceId, $targetType, $targetId],
        );

        return $id;
    }

    /** @return list<array{string, string}> ID and name of each artist whose name matches, ignoring case */
    private function artistsNamedLike(string $name): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row[0], (string) $row[1]],
            $this->connection()->fetchAllNumeric('SELECT id::text, name FROM artists WHERE lower(name) = ? ORDER BY id', [$name]),
        );
    }

    /** @return list<string> */
    private function artistIds(): array
    {
        return $this->connection()->fetchFirstColumn('SELECT id::text FROM artists ORDER BY name') |> (static fn (array $ids): array => array_map(strval(...), $ids));
    }

    /** @return list<array{string, string, string}> role, credited name and artist of each credit of the song */
    private function songCredits(string $songId): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row[0], (string) $row[1], (string) $row[2]],
            $this->connection()->fetchAllNumeric('SELECT role, credited_name, artist_id::text FROM artist_song WHERE song_id = ? ORDER BY role', [$songId]),
        );
    }

    /** @return list<array{string, string, string}> artist, role and credited name of each credit of the album */
    private function albumCredits(): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row[0], (string) $row[1], (string) $row[2]],
            $this->connection()->fetchAllNumeric('SELECT artist_id::text, role, credited_name FROM artist_album WHERE album_id = ? ORDER BY role', [$this->albumId]),
        );
    }

    /** @return list<string> the public IDs of the artists the user favorited */
    private function artistFavoritesOf(string $userId): array
    {
        return $this->connection()->fetchFirstColumn(
            "SELECT entity_public_id FROM user_favorites WHERE user_id = ? AND entity_type = 'artist' ORDER BY entity_public_id",
            [$userId],
        ) |> (static fn (array $ids): array => array_map(strval(...), $ids));
    }

    /** @return array{string, string} the recommendation's source and target IDs */
    private function recommendationEnds(string $recommendationId): array
    {
        $row = $this->connection()->fetchNumeric('SELECT source_id, target_id FROM recommendations WHERE id = ?', [$recommendationId]);
        self::assertIsArray($row);

        return [(string) $row[0], (string) $row[1]];
    }

    /** @return list<string> */
    private function recommendationIds(): array
    {
        return $this->connection()->fetchFirstColumn('SELECT id::text FROM recommendations ORDER BY id') |> (static fn (array $ids): array => array_map(strval(...), $ids));
    }

    private function coverOf(string $artistId): ?string
    {
        $cover = $this->connection()->fetchOne('SELECT cover_image_id::text FROM artists WHERE id = ?', [$artistId]);

        return is_string($cover) ? $cover : null;
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function storage(): StoragePortInterface
    {
        return static::getContainer()->get(StoragePortInterface::class);
    }

    private function isolateSchema(): void
    {
        $connection = $this->connection();
        $this->searchPath = (string) $connection->fetchOne('SHOW search_path');
        $this->schema = 'artist_merge_test_' . (random_bytes(8) |> bin2hex(...));
        $schema = $connection->quoteIdentifier($this->schema);
        $connection->executeStatement('CREATE SCHEMA ' . $schema);
        $connection->executeStatement('SET search_path TO ' . $schema . ', public');
        FinalSchemaBootstrap::bootstrap($connection);
    }
}
