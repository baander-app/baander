<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\FinalSchemaBootstrap;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use DoctrineMigrations\Version20261125151723;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2) . '/migrations/Version20261125151723.php';

/**
 * The recommendation origin on disposable PostgreSQL: stored rows are classified without a live
 * update, the name bound leaves a longer stored name readable, and the count of a user's member
 * rows is served by the partial index.
 */
final class RecommendationOriginMigrationTest extends TestCase
{
    private const string USER = '0199bf3c-8a00-7000-8000-c4a1d0000023';

    private Connection $connection;
    private string $schema;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }
        $this->schema = 'recommendation_origin_' . bin2hex(random_bytes(8));
        $this->connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        FinalSchemaBootstrap::bootstrap($this->connection);
        $this->connection->executeStatement(
            "INSERT INTO users (id, public_id, name, email, password, created_at, updated_at) VALUES (?, 'origin00000000000user', 'Member', 'member@baander.app', 'hash', now(), now())",
            [self::USER],
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->connection->close();
        }
    }

    public function testStoredRowsAreClassifiedWithoutALiveUpdateAndALongerNameStaysReadable(): void
    {
        $this->migrate('down');
        $longName = str_repeat('n', 300);
        $this->insert('global0000000content', null, 'content');
        $this->insert('user000000000default', self::USER, 'default');
        $this->insert('user000000000longname', self::USER, $longName);
        $this->insert('user0000collaborative', self::USER, 'collaborative');
        $before = $this->lastLiveChange();

        $this->migrate('up');

        self::assertSame([
            'global0000000content' => 'generator',
            'user000000000default' => 'member',
            'user000000000longname' => 'member',
            'user0000collaborative' => 'generator',
        ], $this->connection->fetchAllKeyValue('SELECT public_id, origin FROM recommendations ORDER BY public_id COLLATE "C"'));
        self::assertSame($before, $this->lastLiveChange(), 'Classifying the stored rows publishes no live update.');
        self::assertSame(
            ['chk_recommendations_name_length' => false, 'chk_recommendations_origin' => true],
            array_map(static fn (mixed $validated): bool => (bool) $validated, $this->connection->fetchAllKeyValue(
                "SELECT conname, convalidated FROM pg_constraint WHERE connamespace = ?::regnamespace AND conname LIKE 'chk_recommendations_%' ORDER BY conname",
                [$this->schema],
            )),
        );
        self::assertSame($longName, $this->connection->fetchOne("SELECT name FROM recommendations WHERE public_id = 'user000000000longname'"));
        self::assertSame(1, $this->connection->executeStatement("DELETE FROM recommendations WHERE public_id = 'user000000000longname'"));
    }

    public function testANewRowDefaultsToTheGeneratorAndARowOutsideTheChecksIsRefused(): void
    {
        $this->insert('new0000000000default', self::USER, 'default');
        self::assertSame('generator', $this->connection->fetchOne("SELECT origin FROM recommendations WHERE public_id = 'new0000000000default'"));
        $this->insert('new0000000000longest', self::USER, str_repeat('ø', 255), 'member');

        foreach ([
            '256-character name' => fn () => $this->insert('new000000000overlong', self::USER, str_repeat('n', 256), 'member'),
            'unknown origin' => fn () => $this->insert('new0000000000unknown', self::USER, 'default', 'import'),
        ] as $case => $insert) {
            try {
                $insert();
                self::fail($case . ': the check must refuse the row.');
            } catch (DriverException $refusal) {
                self::assertSame('23514', $refusal->getSQLState(), $case);
            }
        }
    }

    public function testTheMemberCountReadsOnlyThePartialIndex(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO recommendations (id, public_id, user_id, name, source_type, source_id, target_type, target_id, score, origin, created_at, updated_at)
            SELECT gen_random_uuid(), lpad(n::text, 21, 'g'), ?, 'collaborative', 'song', 'source', 'song', n::text, 0.5, 'generator', now(), now()
            FROM generate_series(1, 2000) n
            SQL, [self::USER]);
        $this->insert('member000000000000one', self::USER, 'default', 'member');

        // A prepared statement with the user as a parameter and the origin as a literal, as the
        // repository sends it. The plan is forced generic, so the index predicate must be proved
        // from the literal alone; without the full user index and sequential scans, the partial
        // index is the only cheap path, and a plan that cannot prove its predicate shows another.
        $this->connection->executeStatement('DROP INDEX idx_recommendations_user_id');
        $this->connection->executeStatement('SET enable_seqscan = off');
        $this->connection->executeStatement("SET plan_cache_mode = 'force_generic_plan'");
        $this->connection->executeStatement("PREPARE member_count(uuid) AS SELECT count(id) FROM recommendations WHERE user_id = \$1 AND origin = 'member'");
        $plan = implode("\n", $this->connection->fetchFirstColumn('EXPLAIN EXECUTE member_count(' . $this->connection->quote(self::USER) . ')'));
        $this->connection->executeStatement('DEALLOCATE member_count');

        self::assertMatchesRegularExpression('/Index (Only )?Scan using idx_recommendations_user_id_member|Bitmap Index Scan on idx_recommendations_user_id_member/', $plan, $plan);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(id) FROM recommendations WHERE user_id = ? AND origin = 'member'", [self::USER]));
    }

    private function insert(string $publicId, ?string $userId, string $name, ?string $origin = null): void
    {
        $columns = ['id', 'public_id', 'user_id', 'name', 'source_type', 'source_id', 'target_type', 'target_id', 'score', 'created_at', 'updated_at'];
        $values = ['gen_random_uuid()', '?', '?', '?', "'song'", "'source'", "'song'", '?', '0.5', 'now()', 'now()'];
        $parameters = [$publicId, $userId, $name, $publicId];
        if ($origin !== null) {
            $columns[] = 'origin';
            $values[] = '?';
            $parameters[] = $origin;
        }
        $this->connection->executeStatement(
            sprintf('INSERT INTO recommendations (%s) VALUES (%s)', implode(', ', $columns), implode(', ', $values)),
            $parameters,
        );
    }

    private function migrate(string $direction): void
    {
        $migration = new Version20261125151723($this->connection, new NullLogger());
        $migration->{$direction}(new Schema());
        $this->connection->transactional(static function (Connection $connection) use ($migration): void {
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        });
    }

    private function lastLiveChange(): int
    {
        return (int) $this->connection->fetchOne('SELECT coalesce(max(id), 0) FROM query_change_inbox');
    }
}
