<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Kernel;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Metadata\Application\ArtistMetadataEnricher;
use App\Metadata\Application\EnrichmentResult;
use App\Metadata\Application\SongMetadataEnricher;
use App\Shared\Domain\Model\Uuid;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * The enricher's save follows Catalog's guarded write of the ID; the type it saves must not
     * take the ID back off.
     */
    public function testAnArtistNamedLikeTheSearchHitGainsTheSearchedIdInLowercase(): void
    {
        $artist = $this->storedArtist('Enrichment Fixture ' . bin2hex(random_bytes(4)));
        $searched = strtoupper(Uuid::v4()->toString());

        $result = $this->applyData($this->container->get(ArtistMetadataEnricher::class), [
            $artist,
            $this->artistSearchHit($searched, mb_strtoupper($artist->getName())),
            'musicbrainz',
            false,
        ]);

        $row = $this->observer->fetchAssociative('SELECT mbid, type FROM artists WHERE id = ?', [$artist->getId()->toString()]);
        self::assertSame(['mbid' => strtolower($searched), 'type' => 'Person'], $row);
        self::assertContains('mbid', $result->getUpdatedFields());
        $artists = $this->container->get(ArtistPortInterface::class);
        self::assertInstanceOf(ArtistPortInterface::class, $artists);
        self::assertSame($artist->getId()->toString(), $artists->findByMbid(MusicbrainzId::fromString(strtolower($searched)))?->getId()->toString());
    }

    /** @return iterable<string, array{bool}> */
    public static function forcedOrNot(): iterable
    {
        yield 'unforced' => [false];
        yield 'forced' => [true];
    }

    /**
     * A forced sync renames the artist to the hit's name; the ID is decided against the name the
     * artist had, so the rename cannot make the hit match.
     */
    #[DataProvider('forcedOrNot')]
    public function testAnArtistGetsNoIdFromASearchHitNamingOnlyOneOfItsArtists(bool $forceUpdate): void
    {
        $tag = bin2hex(random_bytes(4));
        $artist = $this->storedArtist('Post Malone, Hank Williams Jr. ' . $tag);

        $result = $this->applyData($this->container->get(ArtistMetadataEnricher::class), [
            $artist,
            $this->artistSearchHit(Uuid::v4()->toString(), 'Hank Williams, Jr. ' . $tag),
            'musicbrainz',
            $forceUpdate,
        ]);

        $row = $this->observer->fetchAssociative('SELECT mbid, type FROM artists WHERE id = ?', [$artist->getId()->toString()]);
        self::assertSame(['mbid' => null, 'type' => 'Person'], $row);
        self::assertNotContains('mbid', $result->getUpdatedFields());
    }

    /** The holder's ID came from a tag in lowercase; the search finds it in uppercase. */
    public function testAnIdAnotherArtistHoldsIsNotAssignedAndTheOtherFieldsStillUpdate(): void
    {
        $name = 'Enrichment Fixture ' . bin2hex(random_bytes(4));
        $held = Uuid::v4()->toString();
        $holder = $this->storedArtist($name . ' (alias)', $held);
        $artist = $this->storedArtist($name);

        $result = $this->applyData($this->container->get(ArtistMetadataEnricher::class), [
            $artist,
            $this->artistSearchHit(strtoupper($held), $name),
            'musicbrainz',
            false,
        ]);

        $row = $this->observer->fetchAssociative('SELECT mbid, type, sort_name FROM artists WHERE id = ?', [$artist->getId()->toString()]);
        self::assertSame(['mbid' => null, 'type' => 'Person', 'sort_name' => $name], $row);
        self::assertSame($held, $this->observer->fetchOne('SELECT mbid FROM artists WHERE id = ?', [$holder->getId()->toString()]));
        self::assertNotContains('mbid', $result->getUpdatedFields());
    }

    public function testAForcedSyncKeepsTheIdAnArtistTookFromItsTags(): void
    {
        $tagged = Uuid::v4()->toString();
        $artist = $this->storedArtist('Enrichment Fixture ' . bin2hex(random_bytes(4)), $tagged);

        $result = $this->applyData($this->container->get(ArtistMetadataEnricher::class), [
            $artist,
            $this->artistSearchHit(Uuid::v4()->toString(), $artist->getName()),
            'musicbrainz',
            true,
        ]);

        $row = $this->observer->fetchAssociative('SELECT mbid, type FROM artists WHERE id = ?', [$artist->getId()->toString()]);
        self::assertSame(['mbid' => $tagged, 'type' => 'Person'], $row);
        self::assertNotContains('mbid', $result->getUpdatedFields());
    }

    private function storedArtist(string $name, ?string $mbid = null): Artist
    {
        $artists = $this->container->get(ArtistPortInterface::class);
        self::assertInstanceOf(ArtistPortInterface::class, $artists);
        $artist = Artist::create($name, mbid: $mbid);
        $artists->save($artist);
        $this->created[] = ['artists', $artist->getId()->toString()];

        return $artist;
    }

    /** @return array<string, mixed> a MusicBrainz artist search hit as the enricher reads it */
    private function artistSearchHit(string $mbid, string $name): array
    {
        return [
            'source' => 'musicbrainz',
            'quality' => 1.0,
            'mbid' => $mbid,
            'name' => $name,
            'sortName' => $name,
            'type' => 'Person',
            'country' => null,
            'disambiguation' => null,
            'lifeSpanBegin' => null,
            'lifeSpanEnd' => null,
        ];
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
