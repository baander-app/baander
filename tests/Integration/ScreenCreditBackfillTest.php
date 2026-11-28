<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Port\PersonCreditInput;
use App\Catalog\Domain\ValueObject\DirectorCredit;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Repository\DirectorRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\PersonCreditRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\PersonIdentityRepository;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

final class ScreenCreditBackfillTest extends TestCase
{
    private Connection $connection;
    private EntityManager $manager;
    private string $schema;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }
        $this->schema = 'screen_credit_backfill_' . bin2hex(random_bytes(8));
        $this->connection = ArtistImportIdentityRace::connect('public');
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach (['libraries', 'movies', 'people', 'person_external_ids', 'person_identifier_blocks', 'movie_person_credits', 'movie_person_credit_state'] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->connection, $table);
        }
        $this->manager = ArtistImportIdentityRace::entityManager($this->connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->manager->close();
            $this->connection->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            $this->connection->close();
        }
    }

    public function testFirstCrewBackfillReconcilesMigratedDirectorsAndPreservesThoseMissingFromTheProvider(): void
    {
        $library = new LibraryEntity('Movies', 'screen-credit-test', '/media/screen-credit-test', 'movie', 'local');
        $movie = new MovieEntity(new PublicId(), $library, 'An existing movie');
        $this->manager->persist($library);
        $this->manager->persist($movie);
        $this->manager->flush();
        $directors = new DirectorRepository($this->manager);
        $this->connection->transactional(fn () => $directors->syncMovieDirectors($movie->getId(), [
            new DirectorCredit(41, 'Existing Director', null),
            new DirectorCredit(42, 'Retained Director', null),
        ]));
        $originalCredit = $this->connection->fetchOne(
            "SELECT id FROM movie_person_credits WHERE person_id = (SELECT person_id FROM person_external_ids WHERE external_id = '41')",
        );
        $this->manager->clear();
        $transaction = new class ($this->connection) implements TransactionPortInterface {
            public function __construct(private readonly Connection $connection) {}

            public function run(callable $operation): mixed
            {
                return $this->connection->transactional(static fn () => $operation());
            }
        };
        $identities = new PersonIdentityRepository($this->connection, $transaction, $this->manager);
        $credits = new PersonCreditRepository($this->connection, $identities);
        $crew = [
            new PersonCreditInput(['tmdb_person' => '41'], 'Existing Director', 'crew', 'director', 'Director', department: 'Directing', creditedName: 'A. Director', billingOrder: 7, providerCreditId: 'director-provider-41'),
            new PersonCreditInput(['tmdb_person' => '43'], 'Screenwriter', 'crew', 'writer', 'Writer', department: 'Writing', providerCreditId: 'writer-provider-43'),
        ];

        self::assertTrue($transaction->run(fn () => $credits->syncMovieCredits($movie->getId(), null, $crew)));
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT count(*) FROM movie_person_credits'));
        self::assertSame([
            'id' => $originalCredit,
            'provider_credit_id' => 'director-provider-41',
            'department' => 'Directing',
            'credited_name' => 'A. Director',
            'billing_order' => 7,
            'credit_key' => 'crew:tmdb:director-provider-41',
        ], $this->connection->fetchAssociative("SELECT id, provider_credit_id, department, credited_name, billing_order, credit_key FROM movie_person_credits WHERE person_id = (SELECT person_id FROM person_external_ids WHERE external_id = '41')"));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM movie_person_credits WHERE person_id = (SELECT person_id FROM person_external_ids WHERE external_id = '42')"));
        self::assertFalse($transaction->run(fn () => $credits->syncMovieCredits($movie->getId(), null, $crew)));
        self::assertTrue($transaction->run(fn () => $credits->syncMovieCredits($movie->getId(), null, $crew, true)));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM movie_person_credits'));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM movie_person_credits WHERE person_id = (SELECT person_id FROM person_external_ids WHERE external_id = '42')"));
    }
}
