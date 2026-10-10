<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\Model\Song;
use App\Kernel;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Media\Infrastructure\Doctrine\Entity\ImageEntity;
use App\Metadata\Application\AlbumMetadataEnricher;
use App\Metadata\Application\ArtistMetadataEnricher;
use App\Metadata\Application\EnrichmentResult;
use App\Metadata\Application\SongMetadataEnricher;
use App\Shared\Domain\Model\PublicId;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Enrichment loads its target, spends seconds on provider lookups, then writes. Another process
 * (an operator's edit, a cover extraction) commits through its own connection meanwhile; the
 * enrichment must decide and write against that committed row, not the copy this process still
 * holds in Doctrine's identity map.
 *
 * applyData() is invoked directly because the provider adapters make real HTTP requests; it is
 * the step that runs after the lookups.
 */
#[SkipDatabaseRollback]
final class EnrichmentFreshStateTest extends TestCase
{
    private Kernel $kernel;
    private ContainerInterface $container;
    private Connection $observer;
    private LibraryEntity $library;

    /** @var list<array{string, string}> table and id, deleted in reverse order */
    private array $created = [];

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL and DATABASE_URL to the same fully migrated disposable PostgreSQL database.');
        }

        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $params['serverVersion'] = '18';
        $this->observer = DriverManager::getConnection($params);
        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();
        $this->container = $this->kernel->getContainer()->get('test.service_container');
        $manager = $this->entityManager();
        self::assertSame(0, $manager->getConnection()->getTransactionNestingLevel(), 'The observer must see committed writes.');
        self::assertSame($this->observer->fetchOne('SELECT current_database()'), $manager->getConnection()->fetchOne('SELECT current_database()'));
        $this->library = new LibraryEntity('Enrichment fixture', 'enrichment-' . bin2hex(random_bytes(8)), '/tmp/enrichment-fixture', 'music', 'local');
        $manager->persist($this->library);
        $manager->flush();
        $this->created[] = ['libraries', $this->library->getId()->toString()];
    }

    protected function tearDown(): void
    {
        if (!isset($this->observer)) {
            return;
        }
        foreach ($this->created as [$table, $id]) {
            if ($table === 'albums') {
                $this->observer->executeStatement('UPDATE albums SET cover_image_id = NULL WHERE id = ?', [$id]);
            }
        }
        foreach (array_reverse($this->created) as [$table, $id]) {
            $this->observer->executeStatement('DELETE FROM ' . $table . ' WHERE id = ?', [$id]);
        }
        $this->kernel->shutdown();
        $this->observer->close();
    }

    public function testAnAlbumKeepsAYearLockAndCoverCommittedDuringTheLookupsAndGainsTheOtherFields(): void
    {
        $albums = $this->container->get(AlbumPortInterface::class);
        self::assertInstanceOf(AlbumPortInterface::class, $albums);
        $created = Album::create($this->library->getId(), 'Abbey Road', 'album');
        $albums->save($created);
        $id = $created->getId()->toString();
        $this->created[] = ['albums', $id];
        $cover = new ImageEntity('images/enrichment-fixture.jpg', 'jpg', 'image/jpeg', new PublicId(), 10, 1, 1, 'album');
        $this->entityManager()->persist($cover);
        $this->entityManager()->flush();
        $coverId = $cover->getId()->toString();
        $this->created[] = ['images', $coverId];
        $loaded = $albums->findByUuid($created->getId());
        self::assertNotNull($loaded);

        $this->observer->executeStatement(
            "UPDATE albums SET year = 2019, locked_fields = '[\"year\"]'::jsonb, cover_image_id = ? WHERE id = ?",
            [$coverId, $id],
        );
        $result = $this->applyData($this->container->get(AlbumMetadataEnricher::class), [$loaded, [
            'quality' => 1.0,
            'mbid' => null,
            'title' => 'Abbey Road [Parlophone,PCS 7088,GB]',
            'year' => '1969-09-26',
            'country' => null,
            'tags' => [],
            'genres' => [],
        ], 'musicbrainz', true]);

        $row = $this->observer->fetchAssociative('SELECT year, locked_fields, cover_image_id, label, catalog_number, country FROM albums WHERE id = ?', [$id]);
        self::assertIsArray($row);
        self::assertSame(2019, $row['year']);
        self::assertSame(['year'], json_decode((string) $row['locked_fields'], true));
        self::assertSame($coverId, $row['cover_image_id']);
        self::assertSame('Parlophone', $row['label']);
        self::assertSame('PCS 7088', $row['catalog_number']);
        self::assertSame('GB', $row['country']);
        self::assertNotContains('year', $result->getUpdatedFields());
    }

    public function testAnArtistKeepsACountryLockCommittedDuringTheLookupsAndGainsTheOtherFields(): void
    {
        $artists = $this->container->get(ArtistPortInterface::class);
        self::assertInstanceOf(ArtistPortInterface::class, $artists);
        $created = Artist::create('Enrichment Fixture ' . bin2hex(random_bytes(4)));
        $artists->save($created);
        $id = $created->getId()->toString();
        $this->created[] = ['artists', $id];
        $loaded = $artists->findByUuid($created->getId());
        self::assertNotNull($loaded);

        $this->observer->executeStatement(
            "UPDATE artists SET country = 'GB', locked_fields = '[\"country\"]'::jsonb WHERE id = ?",
            [$id],
        );
        $this->applyData($this->container->get(ArtistMetadataEnricher::class), [$loaded, [
            'quality' => 1.0,
            'mbid' => null,
            'name' => 'Beatles',
            'sortName' => 'Beatles, The',
            'type' => 'Group',
            'country' => 'US',
            'disambiguation' => null,
            'lifeSpanBegin' => null,
            'lifeSpanEnd' => null,
        ], 'musicbrainz', true]);

        $row = $this->observer->fetchAssociative('SELECT country, locked_fields, type, sort_name FROM artists WHERE id = ?', [$id]);
        self::assertIsArray($row);
        self::assertSame('GB', $row['country']);
        self::assertSame(['country'], json_decode((string) $row['locked_fields'], true));
        self::assertSame('Group', $row['type']);
        self::assertSame('Beatles, The', $row['sort_name']);
    }

    /**
     * A cleared entity manager (a worker reset, or the genre repository recovering from a unique
     * violation inside applyData()) makes the save load the row anew and copy every field of the
     * loaded snapshot onto it, the empty cover included.
     */
    public function testAnAlbumKeepsACoverCommittedDuringTheLookupsAfterTheEntityManagerWasCleared(): void
    {
        $albums = $this->container->get(AlbumPortInterface::class);
        self::assertInstanceOf(AlbumPortInterface::class, $albums);
        $created = Album::create($this->library->getId(), 'Let It Be', 'album');
        $albums->save($created);
        $id = $created->getId()->toString();
        $this->created[] = ['albums', $id];
        $cover = new ImageEntity('images/enrichment-cover.jpg', 'jpg', 'image/jpeg', new PublicId(), 10, 1, 1, 'album');
        $this->entityManager()->persist($cover);
        $this->entityManager()->flush();
        $coverId = $cover->getId()->toString();
        $this->created[] = ['images', $coverId];
        $loaded = $albums->findByUuid($created->getId());
        self::assertNotNull($loaded);

        $this->observer->executeStatement('UPDATE albums SET cover_image_id = ? WHERE id = ?', [$coverId, $id]);
        $this->entityManager()->clear();
        $this->applyData($this->container->get(AlbumMetadataEnricher::class), [$loaded, [
            'quality' => 1.0,
            'mbid' => null,
            'title' => 'Let It Be',
            'year' => '1970-05-08',
            'country' => null,
            'tags' => [],
            'genres' => [],
        ], 'musicbrainz', false]);

        $row = $this->observer->fetchAssociative('SELECT cover_image_id, year FROM albums WHERE id = ?', [$id]);
        self::assertIsArray($row);
        self::assertSame($coverId, $row['cover_image_id']);
        self::assertSame(1970, $row['year']);
    }

    public function testASongKeepsAnMbidCommittedDuringTheLookup(): void
    {
        $albums = $this->container->get(AlbumPortInterface::class);
        $songs = $this->container->get(SongPortInterface::class);
        self::assertInstanceOf(AlbumPortInterface::class, $albums);
        self::assertInstanceOf(SongPortInterface::class, $songs);
        $album = Album::create($this->library->getId(), 'Abbey Road', 'album');
        $albums->save($album);
        $this->created[] = ['albums', $album->getId()->toString()];
        $created = Song::create($album->getId(), 'Come Together', '/tmp/enrichment-fixture/come-together.flac', 1024, 'audio/flac');
        $songs->save($created);
        $id = $created->getId()->toString();
        $this->created[] = ['songs', $id];
        $loaded = $songs->findByUuid($created->getId());
        self::assertNotNull($loaded);
        $stored = '6b4f1a46-4a2e-4f3a-9d6c-6a3e2b7f1c22';

        $this->observer->executeStatement('UPDATE songs SET mbid = ? WHERE id = ?', [$stored, $id]);
        $result = $this->applyData($this->container->get(SongMetadataEnricher::class), [$loaded, [
            'source' => 'musicbrainz',
            'quality' => 1.0,
            'mbid' => '0b4f1a46-4a2e-4f3a-9d6c-6a3e2b7f1c11',
            'title' => 'Come Together',
            'tags' => [],
        ], false]);

        self::assertSame($stored, $this->observer->fetchOne('SELECT mbid FROM songs WHERE id = ?', [$id]));
        self::assertSame([], $result->getUpdatedFields());
    }

    /** @param list<mixed> $arguments */
    private function applyData(object $enricher, array $arguments): EnrichmentResult
    {
        $result = (new \ReflectionMethod($enricher, 'applyData'))->invoke($enricher, ...$arguments);
        self::assertInstanceOf(EnrichmentResult::class, $result);
        self::assertTrue($result->isSuccess());

        return $result;
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = $this->container->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
