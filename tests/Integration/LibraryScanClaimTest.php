<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryClaimAttempt;
use App\Library\Domain\ValueObject\LibraryClaimKind;
use App\Library\Domain\ValueObject\LibraryClaimRelease;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Repository\LibraryRepository;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Fixtures\Library\LibraryScanClaimRace;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DoctrineMigrations\Version20261010100000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A scan or a delete with files claims its library with one statement and holds it as a lease.
 * Two sessions race on PostgreSQL: the second waits on the row lock of the first and, once that
 * commits, updates nothing, whether the library was free or its claim had lapsed, and names the
 * kind of claim that holds the library.
 */
final class LibraryScanClaimTest extends TestCase
{
    private const int LEASE = 900;

    private Connection $writer;
    private Connection $observer;
    private EntityManager $manager;
    private LibraryRepository $libraries;
    private string $schema;
    private Library $library;
    private ?Kernel $kernel = null;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }

        $this->schema = 'library_scan_claim_' . bin2hex(random_bytes(8));
        $this->observer = LibraryScanClaimRace::connect('public');
        self::assertStringStartsWith('18.', (string) $this->observer->fetchOne('SHOW server_version'));
        $this->observer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->observer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        $this->writer = LibraryScanClaimRace::connect($this->schema);
        $this->manager = LibraryScanClaimRace::entityManager($this->writer);
        (new SchemaTool($this->manager))->createSchema([$this->manager->getClassMetadata(LibraryEntity::class)]);

        $this->libraries = LibraryScanClaimRace::repository($this->manager);
        $this->library = Library::create('Race', new LibrarySlug('race'), new LibraryPath('/media/race'), LibraryType::Music, FilesystemType::Local);
        $this->libraries->save($this->library);
        $this->manager->clear();
    }

    protected function tearDown(): void
    {
        if (isset($this->writer)) {
            while ($this->writer->isTransactionActive()) {
                $this->writer->rollBack();
            }
            $this->writer->close();
        }
        if (isset($this->observer)) {
            $this->observer->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->observer->close();
        }
        $this->kernel?->shutdown();
    }

    public function testOfTwoConcurrentClaimsExactlyOneSucceeds(): void
    {
        self::assertNull($this->row()['scan_status'], 'A library that never scanned has no status.');

        $claim = new Uuid();
        $this->writer->beginTransaction();
        self::assertTrue($this->libraries->claimScan($this->library->getId(), $claim, self::LEASE)->claimed);
        // The first claim holds the row lock until it commits; the second session waits on it.
        $child = $this->startChild();
        $this->awaitLockWait($child);
        $this->writer->commit();

        self::assertSame(['claimed' => false, 'holder' => 'scan'], $this->finish($child));
        self::assertSame('scanning', $this->row()['scan_status']);
        self::assertSame($claim->toString(), $this->row()['claim_id']);
    }

    public function testAScanWaitingOnADeleteClaimIsRefusedNamingTheDelete(): void
    {
        $this->completeAScan();
        $before = $this->row();

        $this->writer->beginTransaction();
        self::assertTrue($this->libraries->claimDelete($this->library->getId(), new Uuid(), self::LEASE)->claimed);
        $child = $this->startChild();
        $this->awaitLockWait($child);
        $this->writer->commit();

        self::assertSame(['claimed' => false, 'holder' => 'delete'], $this->finish($child));
        $row = $this->row();
        self::assertSame('delete', $row['claim_kind']);
        self::assertSame([$before['scan_status'], $before['last_scan']], [$row['scan_status'], $row['last_scan']], 'A delete claim leaves the scan status and time alone.');
    }

    public function testAClaimRolledBackLeavesTheLibraryClaimableForTheWaitingSession(): void
    {
        $this->writer->beginTransaction();
        self::assertTrue($this->libraries->claimDelete($this->library->getId(), new Uuid(), self::LEASE)->claimed);
        $child = $this->startChild();
        $this->awaitLockWait($child);
        $this->writer->rollBack();

        self::assertSame(['claimed' => true, 'holder' => null], $this->finish($child));
        self::assertSame(['scanning', 'scan'], [$this->row()['scan_status'], $this->row()['claim_kind']]);
    }

    public function testOfTwoConcurrentTakeoversOfALapsedClaimExactlyOneSucceeds(): void
    {
        $lost = new Uuid();
        self::assertTrue($this->libraries->claimScan($this->library->getId(), $lost, self::LEASE)->claimed);
        $this->lapse();

        $takeover = new Uuid();
        $this->writer->beginTransaction();
        self::assertTrue($this->libraries->claimScan($this->library->getId(), $takeover, self::LEASE)->claimed);
        $child = $this->startChild();
        $this->awaitLockWait($child);
        $this->writer->commit();

        // The waiting session re-reads the committed row, whose new lease is live.
        self::assertSame(['claimed' => false, 'holder' => 'scan'], $this->finish($child));
        self::assertSame($takeover->toString(), $this->row()['claim_id']);
        self::assertTrue($this->observer->fetchOne(
            'SELECT claim_expires_at > clock_timestamp() + interval \'800 seconds\' FROM libraries WHERE id = ?',
            [$this->library->getId()->toString()],
        ));
        self::assertFalse($this->libraries->renewClaim($lost, self::LEASE), 'The lost scan cannot renew the new claim.');
        self::assertFalse($this->libraries->endScanClaim($lost, completed: false), 'The lost scan cannot end the new claim.');
        self::assertSame('scanning', $this->row()['scan_status']);
    }

    public function testALiveClaimRefusesOtherClaimsButNotItsOwnScan(): void
    {
        $claim = new Uuid();
        self::assertTrue($this->libraries->claimScan($this->library->getId(), $claim, self::LEASE)->claimed);

        self::assertEquals(LibraryClaimAttempt::heldBy(LibraryClaimKind::Scan), $this->libraries->claimScan($this->library->getId(), new Uuid(), self::LEASE));
        self::assertEquals(LibraryClaimAttempt::heldBy(LibraryClaimKind::Scan), $this->libraries->claimDelete($this->library->getId(), new Uuid(), self::LEASE));
        self::assertTrue($this->libraries->claimScan($this->library->getId(), $claim, self::LEASE)->claimed, 'The scan holding the claim takes it again when it starts.');
        self::assertSame(LibraryClaimKind::Scan, $this->libraries->liveClaimKind($this->library->getId()));

        // A lapsed claim is still its scan's until another takes it over.
        $this->lapse();
        self::assertNull($this->libraries->liveClaimKind($this->library->getId()));
        self::assertTrue($this->libraries->renewClaim($claim, self::LEASE));
        self::assertFalse($this->libraries->claimScan($this->library->getId(), new Uuid(), self::LEASE)->claimed);
        self::assertEquals(LibraryClaimAttempt::noLibrary(), $this->libraries->claimScan(new Uuid(), new Uuid(), self::LEASE));
        self::assertEquals(LibraryClaimAttempt::noLibrary(), $this->libraries->claimDelete(new Uuid(), new Uuid(), self::LEASE));
    }

    public function testADeleteClaimRefusesScansAndDeletesAndLeavesTheScanStatusAndTimeAlone(): void
    {
        $this->completeAScan();
        $before = $this->row();

        $claim = new Uuid();
        self::assertEquals(LibraryClaimAttempt::claimed(), $this->libraries->claimDelete($this->library->getId(), $claim, self::LEASE));
        self::assertSame(LibraryClaimKind::Delete, $this->libraries->liveClaimKind($this->library->getId()));
        self::assertEquals(LibraryClaimAttempt::heldBy(LibraryClaimKind::Delete), $this->libraries->claimScan($this->library->getId(), new Uuid(), self::LEASE));
        self::assertEquals(LibraryClaimAttempt::heldBy(LibraryClaimKind::Delete), $this->libraries->claimDelete($this->library->getId(), new Uuid(), self::LEASE));
        self::assertSame('completed', $this->libraries->findByUuid($this->library->getId())?->getDiscoveryStatus());
        self::assertTrue($this->libraries->renewClaim($claim, self::LEASE));
        self::assertFalse($this->libraries->endScanClaim($claim, completed: false), 'A scan outcome cannot end a delete claim.');

        self::assertTrue($this->libraries->endDeleteClaim($claim));
        self::assertFalse($this->libraries->endDeleteClaim($claim), 'An ended claim stays ended.');
        self::assertSame($before, $this->row());
    }

    public function testADeleteTakesOverTheLapsedClaimOfADeadScanAsAnAbandonedScan(): void
    {
        $dead = new Uuid();
        self::assertTrue($this->libraries->claimScan($this->library->getId(), $dead, self::LEASE)->claimed);
        $this->lapse();

        $delete = new Uuid();
        self::assertTrue($this->libraries->claimDelete($this->library->getId(), $delete, self::LEASE)->claimed);
        $row = $this->row();
        self::assertSame(['failed', $delete->toString(), 'delete', null], [$row['scan_status'], $row['claim_id'], $row['claim_kind'], $row['last_scan']]);
        self::assertFalse($this->libraries->renewClaim($dead, self::LEASE), 'The dead scan cannot renew the delete claim.');
        self::assertFalse($this->libraries->endScanClaim($dead, completed: true), 'The dead scan cannot end the delete claim.');

        self::assertTrue($this->libraries->endDeleteClaim($delete));
        self::assertSame(['scan_status' => 'failed', 'claim_id' => null, 'claim_kind' => null, 'claim_expires_at' => null, 'last_scan' => null], $this->row());
    }

    public function testALapsedDeleteClaimIsTakenOverWithoutTouchingTheScanStatus(): void
    {
        $this->completeAScan();
        self::assertTrue($this->libraries->claimDelete($this->library->getId(), new Uuid(), self::LEASE)->claimed);
        $this->lapse();
        $this->manager->clear();
        self::assertSame('completed', $this->libraries->findByUuid($this->library->getId())?->getDiscoveryStatus(), 'Only a lapsed scan claim reads as failed.');

        self::assertTrue($this->libraries->claimDelete($this->library->getId(), new Uuid(), self::LEASE)->claimed);
        self::assertSame('completed', $this->row()['scan_status']);
        $this->lapse();
        self::assertTrue($this->libraries->claimScan($this->library->getId(), new Uuid(), self::LEASE)->claimed);
        self::assertSame(['scanning', 'scan'], [$this->row()['scan_status'], $this->row()['claim_kind']]);
    }

    public function testEndingAClaimRecordsTheOutcomeAndFreesTheLibrary(): void
    {
        $failed = new Uuid();
        $this->libraries->claimScan($this->library->getId(), $failed, self::LEASE);
        self::assertTrue($this->libraries->endScanClaim($failed, completed: false));
        self::assertSame(['scan_status' => 'failed', 'claim_id' => null, 'claim_kind' => null, 'claim_expires_at' => null, 'last_scan' => null], $this->row());
        self::assertFalse($this->libraries->endScanClaim($failed, completed: true), 'An ended claim stays ended.');

        $completed = new Uuid();
        self::assertTrue($this->libraries->claimScan($this->library->getId(), $completed, self::LEASE)->claimed);
        self::assertTrue($this->libraries->endScanClaim($completed, completed: true));
        $row = $this->row();
        self::assertSame('completed', $row['scan_status']);
        self::assertNull($row['claim_id']);
        self::assertNotNull($row['last_scan']);
    }

    public function testReleaseRefusesALiveClaimUnlessForcedAndReleasesALapsedOne(): void
    {
        self::assertEquals(LibraryClaimRelease::noClaim(), $this->libraries->releaseClaim($this->library->getId(), evenIfLive: false));
        self::assertNull($this->row()['scan_status']);

        $claim = new Uuid();
        $this->libraries->claimScan($this->library->getId(), $claim, self::LEASE);
        self::assertEquals(LibraryClaimRelease::live(LibraryClaimKind::Scan), $this->libraries->releaseClaim($this->library->getId(), evenIfLive: false));
        self::assertSame($claim->toString(), $this->row()['claim_id']);

        $this->lapse();
        self::assertEquals(LibraryClaimRelease::released(LibraryClaimKind::Scan), $this->libraries->releaseClaim($this->library->getId(), evenIfLive: false));
        self::assertSame('failed', $this->row()['scan_status']);
        self::assertFalse($this->libraries->renewClaim($claim, self::LEASE), 'A released claim cannot be renewed.');

        $this->libraries->claimScan($this->library->getId(), new Uuid(), self::LEASE);
        self::assertEquals(LibraryClaimRelease::released(LibraryClaimKind::Scan), $this->libraries->releaseClaim($this->library->getId(), evenIfLive: true));
        self::assertSame(['scan_status' => 'failed', 'claim_id' => null, 'claim_kind' => null, 'claim_expires_at' => null, 'last_scan' => null], $this->row());
    }

    public function testReleasingADeleteClaimNamesItAndLeavesTheScanStatusAlone(): void
    {
        $this->completeAScan();
        $before = $this->row();
        $this->libraries->claimDelete($this->library->getId(), new Uuid(), self::LEASE);
        self::assertEquals(LibraryClaimRelease::live(LibraryClaimKind::Delete), $this->libraries->releaseClaim($this->library->getId(), evenIfLive: false));

        $this->lapse();
        self::assertEquals(LibraryClaimRelease::released(LibraryClaimKind::Delete), $this->libraries->releaseClaim($this->library->getId(), evenIfLive: false));
        self::assertSame($before, $this->row());
    }

    public function testALapsedClaimReadsAsFailed(): void
    {
        $this->libraries->claimScan($this->library->getId(), new Uuid(), self::LEASE);
        self::assertSame('scanning', $this->libraries->findByUuid($this->library->getId())?->getDiscoveryStatus());

        $this->lapse();
        $this->manager->clear();

        self::assertSame('failed', $this->libraries->findByUuid($this->library->getId())?->getDiscoveryStatus());
        self::assertSame('scanning', $this->row()['scan_status'], 'Reading changes nothing.');
    }

    public function testAClaimRefreshesALibraryTheSessionAlreadyLoaded(): void
    {
        $loaded = $this->libraries->findByUuid($this->library->getId());
        self::assertNotNull($loaded);
        self::assertNull($loaded->getDiscoveryStatus());

        self::assertTrue($this->libraries->claimScan($this->library->getId(), new Uuid(), self::LEASE)->claimed);

        self::assertSame('scanning', $this->libraries->findByUuid($this->library->getId())?->getDiscoveryStatus());
    }

    public function testASaveLeavesTheClaimOfAConcurrentScanAlone(): void
    {
        $loaded = $this->libraries->findByUuid($this->library->getId());
        self::assertNotNull($loaded);
        // Another session claims the library after this one loaded it.
        $child = $this->startChild();
        self::assertSame(['claimed' => true, 'holder' => null], $this->finish($child));

        $loaded->updateMetadata(name: 'Renamed');
        $this->libraries->save($loaded);

        self::assertSame('scanning', $this->row()['scan_status']);
        self::assertNotNull($this->row()['claim_id']);
    }

    /** The migrated catalog, not SchemaTool's, ties the status to the claim and its kind. */
    public function testTheMigratedSchemaHoldsTheClaimColumnsAndTiesScanningToAScanClaim(): void
    {
        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();
        $manager = $this->kernel->getContainer()->get('test.service_container')->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $connection = $manager->getConnection();

        self::assertSame(
            [
                ['column_name' => 'claim_expires_at', 'data_type' => 'timestamp with time zone', 'datetime_precision' => 6, 'is_nullable' => 'YES'],
                ['column_name' => 'claim_id', 'data_type' => 'uuid', 'datetime_precision' => null, 'is_nullable' => 'YES'],
                ['column_name' => 'claim_kind', 'data_type' => 'text', 'datetime_precision' => null, 'is_nullable' => 'YES'],
            ],
            $connection->fetchAllAssociative(
                "SELECT column_name, data_type, datetime_precision, is_nullable FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = 'libraries' AND column_name LIKE '%claim%' ORDER BY column_name",
            ),
        );
        self::assertSame(
            'CREATE UNIQUE INDEX uniq_libraries_claim_id ON public.libraries USING btree (claim_id)',
            $connection->fetchOne("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'uniq_libraries_claim_id'"),
        );
        $this->assertSchemaComparisonIsClean($manager);

        $connection->beginTransaction();
        try {
            $id = $this->insertLibrary($connection);
            $violations = [
                'scanning without a claim' => "scan_status = 'scanning'",
                'scanning with a delete claim' => "scan_status = 'scanning', claim_id = gen_random_uuid(), claim_kind = 'delete', claim_expires_at = now()",
                'a scan claim without scanning' => "scan_status = 'completed', claim_id = gen_random_uuid(), claim_kind = 'scan', claim_expires_at = now()",
                'a claim without a kind' => 'claim_id = gen_random_uuid(), claim_expires_at = now()',
                'a kind without a claim' => "claim_kind = 'delete'",
                'an unknown kind' => "claim_id = gen_random_uuid(), claim_kind = 'import', claim_expires_at = now()",
                'a claim without a lease' => "claim_id = gen_random_uuid(), claim_kind = 'delete'",
            ];
            foreach ($violations as $case => $assignments) {
                $connection->executeStatement('SAVEPOINT claim_check');
                try {
                    $connection->executeStatement('UPDATE libraries SET ' . $assignments . ' WHERE id = ?', [$id]);
                    self::fail(sprintf('%s must violate chk_libraries_claim.', ucfirst($case)));
                } catch (DriverException $violation) {
                    self::assertSame('23514', $violation->getSQLState(), $case);
                    self::assertStringContainsString('chk_libraries_claim', $violation->getMessage(), $case);
                }
                $connection->executeStatement('ROLLBACK TO SAVEPOINT claim_check');
            }

            $connection->executeStatement(
                "UPDATE libraries SET scan_status = 'completed', claim_id = gen_random_uuid(), claim_kind = 'delete', claim_expires_at = now() WHERE id = ?",
                [$id],
            );
        } finally {
            $connection->rollBack();
        }
    }

    /** The migration keeps a scan claim held when it runs, and going back drops only delete claims. */
    public function testTheMigrationKindsHeldClaimsAsScansAndItsReversalDropsDeleteClaims(): void
    {
        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();
        $manager = $this->kernel->getContainer()->get('test.service_container')->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $connection = $manager->getConnection();

        $connection->beginTransaction();
        try {
            $scanned = $this->insertLibrary($connection);
            $deleting = $this->insertLibrary($connection);
            $scanClaim = (new Uuid())->toString();
            $connection->executeStatement(
                "UPDATE libraries SET scan_status = 'scanning', claim_id = ?, claim_kind = 'scan', claim_expires_at = now() + interval '1 minute' WHERE id = ?",
                [$scanClaim, $scanned],
            );
            $connection->executeStatement(
                "UPDATE libraries SET scan_status = 'completed', claim_id = gen_random_uuid(), claim_kind = 'delete', claim_expires_at = now() WHERE id = ?",
                [$deleting],
            );

            $this->runMigration($connection, 'down');
            self::assertSame(
                [['id' => $scanned, 'scan_status' => 'scanning', 'scan_claim_id' => $scanClaim], ['id' => $deleting, 'scan_status' => 'completed', 'scan_claim_id' => null]],
                $connection->fetchAllAssociative(
                    'SELECT id, scan_status, scan_claim_id FROM libraries WHERE id IN (?, ?) ORDER BY id = ? DESC',
                    [$scanned, $deleting, $scanned],
                ),
            );

            $this->runMigration($connection, 'up');
            self::assertSame(
                [['claim_id' => $scanClaim, 'claim_kind' => 'scan'], ['claim_id' => null, 'claim_kind' => null]],
                $connection->fetchAllAssociative(
                    'SELECT claim_id, claim_kind FROM libraries WHERE id IN (?, ?) ORDER BY id = ? DESC',
                    [$scanned, $deleting, $scanned],
                ),
            );
        } finally {
            $connection->rollBack();
        }
    }

    private function runMigration(Connection $connection, string $direction): void
    {
        require_once dirname(__DIR__, 2) . '/migrations/Version20261010100000.php';
        $migration = new Version20261010100000($connection, new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function insertLibrary(Connection $connection): string
    {
        $id = (new Uuid())->toString();
        $connection->executeStatement(
            "INSERT INTO libraries (id, name, slug, path, type, sort_order, created_at, updated_at, filesystem_type)
             VALUES (?, 'Constraint', ?, '/media/constraint', 'music', 0, now(), now(), 'local')",
            [$id, 'constraint-' . bin2hex(random_bytes(4))],
        );

        return $id;
    }

    private function assertSchemaComparisonIsClean(EntityManagerInterface $manager): void
    {
        $tool = new SchemaTool($manager);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $catalog = $manager->getConnection()->createSchemaManager();
        $names = ['libraries'];
        foreach ([$catalog->introspectTable('libraries'), $tool->getSchemaFromMetadata($metadata)->getTable('libraries')] as $side) {
            foreach ($side->getIndexes() as $index) {
                $names[] = $index->getName();
            }
        }
        $pattern = '/\b(' . implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), array_unique($names))) . ')\b/i';

        // The disposable schema of the other tests holds a SchemaTool copy of the table; skip it.
        $schema = $this->schema;
        self::assertSame([], array_values(array_filter(
            $tool->getUpdateSchemaSql($metadata),
            static fn (string $sql): bool => preg_match($pattern, $sql) === 1 && !str_contains($sql, $schema . '.'),
        )));
    }

    /** Leaves the library with a completed scan and a last scan time, as a delete may find it. */
    private function completeAScan(): void
    {
        $claim = new Uuid();
        self::assertTrue($this->libraries->claimScan($this->library->getId(), $claim, self::LEASE)->claimed);
        self::assertTrue($this->libraries->endScanClaim($claim, completed: true));
        self::assertNotNull($this->row()['last_scan']);
    }

    /** Moves the claim's lease into the past, as if its holder stopped renewing it. */
    private function lapse(): void
    {
        self::assertSame(1, $this->observer->executeStatement(
            "UPDATE libraries SET claim_expires_at = clock_timestamp() - interval '1 second' WHERE id = ? AND claim_id IS NOT NULL",
            [$this->library->getId()->toString()],
        ));
    }

    /** @return array{scan_status: ?string, claim_id: ?string, claim_kind: ?string, claim_expires_at: ?string, last_scan: ?string} */
    private function row(): array
    {
        $row = $this->observer->fetchAssociative(
            'SELECT scan_status, claim_id, claim_kind, claim_expires_at, last_scan FROM libraries WHERE id = ?',
            [$this->library->getId()->toString()],
        );
        self::assertIsArray($row);

        /** @var array{scan_status: ?string, claim_id: ?string, claim_kind: ?string, claim_expires_at: ?string, last_scan: ?string} $row */
        return $row;
    }

    /** @return array{process: resource, output: resource, application: string} */
    private function startChild(): array
    {
        $application = 'library_claim_' . bin2hex(random_bytes(6));
        $output = tmpfile();
        self::assertIsResource($output);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/Library/library-scan-claim.php', $this->schema, $application, $this->library->getId()->toString()],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        self::assertIsResource($process);

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }

    /** @param array{process: resource, output: resource, application: string} $child */
    private function awaitLockWait(array $child): void
    {
        $deadline = microtime(true) + 5;
        while (true) {
            $this->observer->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
            $waiting = $this->observer->fetchOne(
                "SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'",
                [$child['application']],
            );
            if ($waiting !== false) {
                return;
            }
            if (microtime(true) > $deadline || !proc_get_status($child['process'])['running']) {
                rewind($child['output']);
                self::fail('The second claim must wait on the library row lock: ' . stream_get_contents($child['output']));
            }
            usleep(1000);
        }
    }

    /**
     * @param array{process: resource, output: resource, application: string} $child
     *
     * @return array<string, mixed>
     */
    private function finish(array $child): array
    {
        $deadline = microtime(true) + 10;
        while (proc_get_status($child['process'])['running']) {
            if (microtime(true) > $deadline) {
                proc_terminate($child['process'], 9);
                self::fail('The second claim did not finish after the first ended.');
            }
            usleep(1000);
        }
        rewind($child['output']);
        $result = (string) stream_get_contents($child['output']);
        fclose($child['output']);
        self::assertSame(0, proc_close($child['process']), $result);

        $decoded = json_decode($result, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
