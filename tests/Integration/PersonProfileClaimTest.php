<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Infrastructure\Doctrine\Repository\PersonIdentityRepository;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261121194800;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PersonProfileClaimTest extends TestCase
{
    private Connection $connection;
    private Connection $contender;
    private string $schema;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }
        $this->connection = ArtistImportIdentityRace::connect('public');
        $this->schema = 'person_profile_claim_' . bin2hex(random_bytes(8));
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach (['people', 'person_external_ids', 'person_identifier_blocks'] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->connection, $table);
        }
        $this->connection->executeStatement('ALTER TABLE person_external_ids DROP COLUMN IF EXISTS profile_claimed_until');
        require_once dirname(__DIR__, 2) . '/migrations/Version20261121194800.php';
        $migration = new Version20261121194800($this->connection, new NullLogger());
        $this->connection->transactional(function () use ($migration): void {
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        });
        $this->contender = ArtistImportIdentityRace::connect($this->schema);
    }

    protected function tearDown(): void
    {
        if (!isset($this->connection)) {
            return;
        }
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        if (isset($this->contender)) {
            $this->contender->close();
        }
        $this->connection->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        $this->connection->close();
    }

    public function testConcurrentPagesSkipLockedAndPreviouslyClaimedPeople(): void
    {
        foreach (range(10, 16) as $externalId) {
            $this->person((string) $externalId);
        }
        $first = self::identities($this->connection);
        $second = self::identities($this->contender);
        $this->connection->beginTransaction();
        self::assertSame(['10', '11', '12'], $first->pendingTmdbPeople(3));
        self::assertSame(['13', '14', '15', '16'], $second->pendingTmdbPeople(5));
        self::assertSame([], $second->pendingTmdbPeople(5));
        $this->connection->commit();
        self::assertSame([], $first->pendingTmdbPeople(5));
        self::assertSame('timestamp with time zone', $this->connection->fetchOne(
            "SELECT data_type FROM information_schema.columns WHERE table_schema = :schema AND table_name = 'person_external_ids' AND column_name = 'profile_claimed_until'",
            ['schema' => $this->schema],
        ));
    }

    public function testRolledBackAndExpiredClaimsBecomeEligibleAgain(): void
    {
        $this->person('10');
        $first = self::identities($this->connection);
        $second = self::identities($this->contender);
        $this->connection->beginTransaction();
        self::assertSame(['10'], $first->pendingTmdbPeople(5));
        $this->connection->rollBack();
        self::assertSame(['10'], $second->pendingTmdbPeople(5));
        self::assertSame([], $first->pendingTmdbPeople(5));
        $this->connection->executeStatement("UPDATE person_external_ids SET profile_claimed_until = CURRENT_TIMESTAMP - INTERVAL '1 second'");
        self::assertSame(['10'], $first->pendingTmdbPeople(5));
    }

    public function testClaimsAreBoundedAndRespectTheCursorAndCompletedProfiles(): void
    {
        foreach (range(10, 17) as $externalId) {
            $this->person((string) $externalId);
        }
        $this->connection->executeStatement("UPDATE person_external_ids SET profile_fetched_at = CURRENT_TIMESTAMP WHERE external_id = '12'");
        $identities = self::identities($this->connection);

        self::assertSame([], $identities->pendingTmdbPeople(0));
        self::assertSame(['13', '14', '15', '16', '17'], $identities->pendingTmdbPeople(100, '11'));
        self::assertSame(['10', '11'], $identities->pendingTmdbPeople(5));
    }

    public function testSuccessfulEnrichmentFinishesTheClaim(): void
    {
        $this->person('42');
        $identities = self::identities($this->connection);
        self::assertSame(['42'], $identities->pendingTmdbPeople(5));

        $identities->enrichTmdbPerson('42', [], ['name' => 'Updated Person']);

        $row = $this->connection->fetchAssociative("SELECT profile_claimed_until, profile_fetched_at FROM person_external_ids WHERE external_id = '42'");
        self::assertIsArray($row);
        self::assertNull($row['profile_claimed_until']);
        self::assertNotNull($row['profile_fetched_at']);
        self::assertSame([], $identities->pendingTmdbPeople(5, '41'));
    }

    private function person(string $externalId): void
    {
        $id = Uuid::v7()->toString();
        $this->connection->insert('people', ['id' => $id, 'public_id' => new PublicId()->toString(), 'name' => 'Person ' . $externalId]);
        $this->connection->insert('person_external_ids', [
            'namespace' => 'tmdb_person',
            'external_id' => $externalId,
            'person_id' => $id,
            'source' => 'tmdb',
        ]);
    }

    private static function identities(Connection $connection): PersonIdentityRepository
    {
        $transaction = new class ($connection) implements TransactionPortInterface {
            public function __construct(private readonly Connection $connection) {}

            public function run(callable $operation): mixed
            {
                return $this->connection->transactional(static fn() => $operation());
            }
        };

        return new PersonIdentityRepository($connection, $transaction, ArtistImportIdentityRace::entityManager($connection));
    }
}
