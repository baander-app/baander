<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Kernel;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Lyrics\Domain\Model\Lyrics;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Lyrics\Infrastructure\Doctrine\Entity\LyricsEntity;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistSongEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Scalar song references in Lyrics and Playlist keep the migrated constraints; disposable PostgreSQL only. */
final class SongReferencePersistenceTest extends TestCase
{
    /** @var array<string, array{class-string, string}> table => [entity, migration-defined FK name] */
    private const ENTITIES = [
        'lyrics' => [LyricsEntity::class, 'lyrics_song_id_fkey'],
        'playlist_song' => [PlaylistSongEntity::class, 'playlist_song_song_id_fkey'],
    ];

    private Kernel $kernel;
    private ContainerInterface $container;
    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Requires fully migrated disposable PostgreSQL.');
        }

        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();
        $this->container = $this->kernel->getContainer()->get('test.service_container');

        $manager = $this->container->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        $this->manager = $manager;
        $this->manager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->manager)) {
            $this->manager->getConnection()->rollBack();
            $this->manager->clear();
        }

        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    public function testSongReferencesAreScalarUuidFields(): void
    {
        foreach (self::ENTITIES as [$entity]) {
            $metadata = $this->manager->getClassMetadata($entity);
            self::assertTrue($metadata->hasField('songId'), $entity);
            self::assertFalse($metadata->hasAssociation('song'), $entity);
            self::assertSame('song_id', $metadata->getColumnName('songId'), $entity);
            self::assertSame('uuid', $metadata->getTypeOfField('songId'), $entity);
        }
    }

    public function testDeclaredForeignKeysMatchTheCatalog(): void
    {
        $tool = new SchemaTool($this->manager);
        $schema = $tool->getSchemaFromMetadata($this->manager->getMetadataFactory()->getAllMetadata());
        $catalog = $this->manager->getConnection()->createSchemaManager();

        foreach (self::ENTITIES as $table => [, $name]) {
            $expected = $schema->getTable($table)->getForeignKey($name);
            self::assertSame(['song_id'], $expected->getLocalColumns(), $table);
            self::assertSame('songs', $expected->getForeignTableName(), $table);
            self::assertSame(['id'], $expected->getForeignColumns(), $table);
            self::assertSame('CASCADE', $expected->onDelete(), $table);

            $actual = $catalog->introspectTable($table)->getForeignKey($name);
            self::assertSame($actual->getLocalColumns(), $expected->getLocalColumns(), $table);
            self::assertSame($actual->getForeignTableName(), $expected->getForeignTableName(), $table);
            self::assertSame($actual->getForeignColumns(), $expected->getForeignColumns(), $table);
            self::assertSame($actual->onDelete(), $expected->onDelete(), $table);
        }
    }

    public function testSchemaComparisonIsCleanForSongReferenceTables(): void
    {
        $tool = new SchemaTool($this->manager);
        $metadata = $this->manager->getMetadataFactory()->getAllMetadata();
        $catalog = $this->manager->getConnection()->createSchemaManager();
        $mapped = $tool->getSchemaFromMetadata($metadata);

        // PostgreSQL's DROP INDEX names only the index, so match every asset on these tables,
        // including DBAL's implicit foreign-key index names on either side of the comparison.
        $names = [];
        foreach (array_keys(self::ENTITIES) as $table) {
            $names[] = $table;
            foreach ([$catalog->introspectTable($table), $mapped->getTable($table)] as $side) {
                foreach ($side->getIndexes() as $index) {
                    $names[] = $index->getName();
                }
                foreach ($side->getForeignKeys() as $foreignKey) {
                    $names[] = $foreignKey->getName();
                }
            }
        }
        $pattern = '/\b(' . implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), array_unique($names))) . ')\b/i';

        $statements = array_values(array_filter(
            $tool->getUpdateSchemaSql($metadata),
            static fn (string $sql): bool => preg_match($pattern, $sql) === 1,
        ));

        self::assertSame([], $statements);
    }

    public function testDeletingASongCascadesItsLyricsAndPlaylistEntriesOnly(): void
    {
        $owner = new UserEntity(new PublicId(), 'Song reference owner', 'song-reference-' . bin2hex(random_bytes(6)) . '@baander.app', 'unused', '');
        $library = new LibraryEntity('Song references', 'song-references-' . bin2hex(random_bytes(6)), '/song-references', 'music', 'local');
        $album = new AlbumEntity(new PublicId(), $library, 'References', 'album');
        $doomed = new SongEntity(new PublicId(), $album, 'Doomed', '/song-references/doomed.flac', 1, 'audio/flac');
        $kept = new SongEntity(new PublicId(), $album, 'Kept', '/song-references/kept.flac', 1, 'audio/flac');
        foreach ([$owner, $library, $album, $doomed, $kept] as $entity) {
            $this->manager->persist($entity);
        }
        $this->manager->flush();

        $lyrics = $this->container->get(LyricsRepositoryInterface::class);
        $playlists = $this->container->get(PlaylistRepositoryInterface::class);
        foreach ([$doomed, $kept] as $song) {
            $lyrics->save(Lyrics::create($song->getId(), 'Lyrics for ' . $song->getTitle(), 'embedded'));
        }
        $playlist = Playlist::create('References', $owner->getId());
        $playlist->addSong($doomed->getId(), 0);
        $playlist->addSong($kept->getId(), 1);
        $playlists->save($playlist);
        $this->manager->clear();

        $connection = $this->manager->getConnection();
        $connection->executeStatement('DELETE FROM songs WHERE id = :id', ['id' => $doomed->getId()->toString()]);

        foreach (array_keys(self::ENTITIES) as $table) {
            $sql = 'SELECT count(*) FROM ' . $table . ' WHERE song_id = :id';
            self::assertSame(0, (int) $connection->fetchOne($sql, ['id' => $doomed->getId()->toString()]), $table);
            self::assertSame(1, (int) $connection->fetchOne($sql, ['id' => $kept->getId()->toString()]), $table);
        }
        self::assertNull($lyrics->findBySongId($doomed->getId()));
        self::assertNotNull($lyrics->findBySongId($kept->getId()));
        $stored = $playlists->findWithSongs($playlist->getId());
        self::assertNotNull($stored);
        self::assertSame(
            [$kept->getId()->toString()],
            array_map(static fn ($song): string => $song->getSongId()->toString(), $stored->getSongs()),
        );
    }

    public function testForeignKeyRejectsLyricsForAnUnknownSong(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->container->get(LyricsRepositoryInterface::class)->save(Lyrics::create(new Uuid(), 'Orphan', 'embedded'));
    }
}
