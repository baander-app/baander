<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Infrastructure\Doctrine\Entity\GenreEntity;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Infrastructure\Doctrine\DQL\PgroongaMatch;
use App\Shared\Infrastructure\Doctrine\Platform\BaanderDriverMiddleware;
use App\Shared\Infrastructure\Doctrine\Repository\PgroongaSearchTrait;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/** Real PGroonga indexes execute the production DQL node and shared search trait. */
final class PgroongaSearchCompatibilityTest extends TestCase
{
    private Connection $connection;
    private EntityManager $entityManager;
    private PgroongaSearchCompatibilityProbe $probe;
    private string $schema;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database with PGroonga.');
        }

        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $params['serverVersion'] = '18';
        $params['charset'] = 'utf8';
        $dbalConfig = new Configuration();
        $dbalConfig->setMiddlewares([new BaanderDriverMiddleware()]);
        $this->connection = DriverManager::getConnection($params, $dbalConfig);
        $this->connection->executeStatement('CREATE EXTENSION IF NOT EXISTS pgroonga');
        $this->schema = 'pgroonga_search_test_' . bin2hex(random_bytes(8));
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');

        CustomTypesRegistrar::register();
        $config = ORMSetup::createAttributeMetadataConfig([
            dirname(__DIR__, 2) . '/src/Catalog/Infrastructure/Doctrine/Entity',
        ], isDevMode: true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy());
        $config->enableNativeLazyObjects(true);
        $config->addCustomStringFunction('pgroonga_match', PgroongaMatch::class);
        $this->entityManager = new EntityManager($this->connection, $config);
        // Exercise actual index options and inherited timestamps, without changing production mappings.
        (new SchemaTool($this->entityManager))->createSchema([
            $this->entityManager->getClassMetadata(GenreEntity::class),
        ]);
        foreach (['Jazz fusion', "O'Brien Jazz", '東京ジャズ', 'Classical strings'] as $index => $name) {
            $this->entityManager->persist(new GenreEntity($name, 'search-fixture-' . $index));
        }
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->probe = new PgroongaSearchCompatibilityProbe($this->entityManager);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            while ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
        if (isset($this->entityManager)) {
            $this->entityManager->clear();
        }
        if (isset($this->schema)) {
            $this->connection->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    public function testInstalledExtensionAndActualIndexUseTheVersionTwoApi(): void
    {
        $version = $this->connection->fetchOne("SELECT extversion FROM pg_extension WHERE extname = 'pgroonga'");
        self::assertIsString($version);
        self::assertTrue(version_compare($version, '2.0.4', '>='), 'The scored tuple signature requires PGroonga 2.0.4 or later.');

        $index = $this->connection->fetchAssociative(
            'SELECT am.amname, opc.opcname, i.indisvalid, ic.reloptions::text AS options
             FROM pg_index i
             JOIN pg_class ic ON ic.oid = i.indexrelid
             JOIN pg_namespace n ON n.oid = ic.relnamespace
             JOIN pg_am am ON am.oid = ic.relam
             JOIN pg_opclass opc ON opc.oid = i.indclass[0]
             WHERE n.nspname = ? AND ic.relname = ?',
            [$this->schema, 'idx_genres_name_pgroonga'],
        );
        self::assertNotFalse($index);
        self::assertSame('pgroonga', $index['amname']);
        self::assertSame('pgroonga_text_full_text_search_ops_v2', $index['opcname']);
        self::assertTrue($index['indisvalid']);
        self::assertStringContainsString('TokenNgram', $index['options']);
        self::assertStringContainsString('TokenFilterStem', $index['options']);
        self::assertStringContainsString('token_filters/stem', $index['options']);
    }

    public function testProductionDqlFilterHandlesUnicodeApostrophesAndEmptySearch(): void
    {
        self::assertSame(['東京ジャズ'], $this->names($this->probe->filter(SearchOptions::create('東京'))));
        self::assertSame(["O'Brien Jazz"], $this->names($this->probe->filter(SearchOptions::create("O'Brien"))));
        self::assertSame(['Jazz fusion', "O'Brien Jazz"], $this->names($this->probe->filter(SearchOptions::create('Jazz'))));
        self::assertCount(4, $this->probe->filter(SearchOptions::create('')));
    }

    public function testProductionScoredSearchUsesIndexedTupleScoresAndPagination(): void
    {
        // A tiny fixture otherwise invites a sequential scan, whose PGroonga scores are zero.
        // Force the index path only to verify compatibility; this is not a planner benchmark.
        $this->connection->executeStatement('SET enable_seqscan = off');
        $plan = $this->connection->fetchFirstColumn("EXPLAIN SELECT * FROM genres WHERE name &@~ 'Jaz*'");
        self::assertStringContainsString('idx_genres_name_pgroonga', implode("\n", $plan));

        $result = $this->probe->scored(SearchOptions::create('Jaz'));
        self::assertSame(2, $result['total']);
        self::assertSame(['Jazz fusion', "O'Brien Jazz"], $this->names($result['entities']));
        self::assertGreaterThan(0.0, $result['highestScore']);

        $page = $this->probe->scored(SearchOptions::create('Jaz', limit: 1, offset: 1));
        self::assertSame(2, $page['total']);
        self::assertCount(1, $page['entities']);
        self::assertGreaterThan(0.0, $page['highestScore']);
        self::assertSame(['entities' => [], 'total' => 0, 'highestScore' => 0.0], $this->probe->scored(SearchOptions::create('')));
    }

    /** @param list<GenreEntity> $entities
     * @return list<string>
     */
    private function names(array $entities): array
    {
        $names = array_map(static fn (GenreEntity $entity): string => $entity->getName(), $entities);
        sort($names);

        return $names;
    }
}

/** Exposes production trait methods with a small, actual mapped catalog entity. */
final class PgroongaSearchCompatibilityProbe
{
    use PgroongaSearchTrait;

    public function __construct(private readonly EntityManager $entityManager) {}

    /** @return list<GenreEntity> */
    public function filter(SearchOptions $options): array
    {
        $qb = $this->entityManager->createQueryBuilder()->select('g')->from(GenreEntity::class, 'g');

        return $this->buildFilterQuery($options, $qb, 'g.name', static function (QueryBuilder $query, array $filters): void {})
            ->getQuery()->getResult();
    }

    /** @return array{entities: list<GenreEntity>, total: int, highestScore: float} */
    public function scored(SearchOptions $options): array
    {
        return $this->buildScoredQuery($options, $this->entityManager, GenreEntity::class, 'genres', 'name');
    }
}
