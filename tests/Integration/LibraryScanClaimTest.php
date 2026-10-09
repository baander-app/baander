<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Domain\ValueObject\ScanClaimRelease;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Repository\LibraryRepository;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Fixtures\Library\LibraryScanClaimRace;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * A scan claims its library with one conditional update and holds it as a lease. Two sessions
 * race on PostgreSQL: the second waits on the row lock of the first and, once that commits,
 * updates nothing, whether the library was free or its claim had lapsed.
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
        self::assertTrue($this->libraries->claimScan($this->library->getId(), $claim, self::LEASE));
        // The first claim holds the row lock until it commits; the second session waits on it.
        $child = $this->startChild();
        $this->awaitLockWait($child);
        $this->writer->commit();

        self::assertSame(['claimed' => false], $this->finish($child));
        self::assertSame('scanning', $this->row()['scan_status']);
        self::assertSame($claim->toString(), $this->row()['scan_claim_id']);
    }

    public function testAClaimRolledBackLeavesTheLibraryClaimableForTheWaitingSession(): void
    {
        $this->writer->beginTransaction();
        self::assertTrue($this->libraries->claimScan($this->library->getId(), new Uuid(), self::LEASE));
        $child = $this->startChild();
        $this->awaitLockWait($child);
        $this->writer->rollBack();

        self::assertSame(['claimed' => true], $this->finish($child));
        self::assertSame('scanning', $this->row()['scan_status']);
    }

    public function testOfTwoConcurrentTakeoversOfALapsedClaimExactlyOneSucceeds(): void
    {
        $lost = new Uuid();
        self::assertTrue($this->libraries->claimScan($this->library->getId(), $lost, self::LEASE));
        $this->lapse();

        $takeover = new Uuid();
        $this->writer->beginTransaction();
        self::assertTrue($this->libraries->claimScan($this->library->getId(), $takeover, self::LEASE));
        $child = $this->startChild();
        $this->awaitLockWait($child);
        $this->writer->commit();

        // The waiting session re-reads the committed row, whose new lease is live.
        self::assertSame(['claimed' => false], $this->finish($child));
        self::assertSame($takeover->toString(), $this->row()['scan_claim_id']);
        self::assertTrue($this->observer->fetchOne(
            'SELECT scan_claim_expires_at > clock_timestamp() + interval \'800 seconds\' FROM libraries WHERE id = ?',
            [$this->library->getId()->toString()],
        ));
        self::assertFalse($this->libraries->renewScanClaim($lost, self::LEASE), 'The lost scan cannot renew the new claim.');
        self::assertFalse($this->libraries->endScanClaim($lost, completed: false), 'The lost scan cannot end the new claim.');
        self::assertSame('scanning', $this->row()['scan_status']);
    }

    public function testALiveClaimRefusesOtherClaimsButNotItsOwnScan(): void
    {
        $claim = new Uuid();
        self::assertTrue($this->libraries->claimScan($this->library->getId(), $claim, self::LEASE));

        self::assertFalse($this->libraries->claimScan($this->library->getId(), new Uuid(), self::LEASE));
        self::assertTrue($this->libraries->claimScan($this->library->getId(), $claim, self::LEASE), 'The scan holding the claim takes it again when it starts.');

        // A lapsed claim is still its scan's until another takes it over.
        $this->lapse();
        self::assertTrue($this->libraries->renewScanClaim($claim, self::LEASE));
        self::assertFalse($this->libraries->claimScan($this->library->getId(), new Uuid(), self::LEASE));
    }

    public function testEndingAClaimRecordsTheOutcomeAndFreesTheLibrary(): void
    {
        $failed = new Uuid();
        $this->libraries->claimScan($this->library->getId(), $failed, self::LEASE);
        self::assertTrue($this->libraries->endScanClaim($failed, completed: false));
        self::assertSame(['scan_status' => 'failed', 'scan_claim_id' => null, 'scan_claim_expires_at' => null, 'last_scan' => null], $this->row());
        self::assertFalse($this->libraries->endScanClaim($failed, completed: true), 'An ended claim stays ended.');

        $completed = new Uuid();
        self::assertTrue($this->libraries->claimScan($this->library->getId(), $completed, self::LEASE));
        self::assertTrue($this->libraries->endScanClaim($completed, completed: true));
        $row = $this->row();
        self::assertSame('completed', $row['scan_status']);
        self::assertNull($row['scan_claim_id']);
        self::assertNotNull($row['last_scan']);
    }

    public function testReleaseRefusesALiveClaimUnlessForcedAndReleasesALapsedOne(): void
    {
        self::assertSame(ScanClaimRelease::NoClaim, $this->libraries->releaseScanClaim($this->library->getId(), evenIfLive: false));
        self::assertNull($this->row()['scan_status']);

        $claim = new Uuid();
        $this->libraries->claimScan($this->library->getId(), $claim, self::LEASE);
        self::assertSame(ScanClaimRelease::Live, $this->libraries->releaseScanClaim($this->library->getId(), evenIfLive: false));
        self::assertSame($claim->toString(), $this->row()['scan_claim_id']);

        $this->lapse();
        self::assertSame(ScanClaimRelease::Released, $this->libraries->releaseScanClaim($this->library->getId(), evenIfLive: false));
        self::assertSame('failed', $this->row()['scan_status']);
        self::assertFalse($this->libraries->renewScanClaim($claim, self::LEASE), 'A released claim cannot be renewed.');

        $this->libraries->claimScan($this->library->getId(), new Uuid(), self::LEASE);
        self::assertSame(ScanClaimRelease::Released, $this->libraries->releaseScanClaim($this->library->getId(), evenIfLive: true));
        self::assertSame(['scan_status' => 'failed', 'scan_claim_id' => null, 'scan_claim_expires_at' => null, 'last_scan' => null], $this->row());
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

        self::assertTrue($this->libraries->claimScan($this->library->getId(), new Uuid(), self::LEASE));

        self::assertSame('scanning', $this->libraries->findByUuid($this->library->getId())?->getDiscoveryStatus());
    }

    public function testASaveLeavesTheClaimOfAConcurrentScanAlone(): void
    {
        $loaded = $this->libraries->findByUuid($this->library->getId());
        self::assertNotNull($loaded);
        // Another session claims the library after this one loaded it.
        $child = $this->startChild();
        self::assertSame(['claimed' => true], $this->finish($child));

        $loaded->updateMetadata(name: 'Renamed');
        $this->libraries->save($loaded);

        self::assertSame('scanning', $this->row()['scan_status']);
        self::assertNotNull($this->row()['scan_claim_id']);
    }

    /** The migrated catalog, not SchemaTool's, ties the status to the claim. */
    public function testTheMigratedSchemaHoldsTheClaimColumnsAndTiesScanningToAClaim(): void
    {
        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();
        $manager = $this->kernel->getContainer()->get('test.service_container')->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $connection = $manager->getConnection();

        self::assertSame(
            [
                ['column_name' => 'scan_claim_expires_at', 'data_type' => 'timestamp with time zone', 'datetime_precision' => 6, 'is_nullable' => 'YES'],
                ['column_name' => 'scan_claim_id', 'data_type' => 'uuid', 'datetime_precision' => null, 'is_nullable' => 'YES'],
            ],
            $connection->fetchAllAssociative(
                "SELECT column_name, data_type, datetime_precision, is_nullable FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = 'libraries' AND column_name LIKE 'scan_claim%' ORDER BY column_name",
            ),
        );
        self::assertSame(
            'CREATE UNIQUE INDEX uniq_libraries_scan_claim_id ON public.libraries USING btree (scan_claim_id)',
            $connection->fetchOne("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'uniq_libraries_scan_claim_id'"),
        );
        $this->assertSchemaComparisonIsClean($manager);

        $connection->beginTransaction();
        try {
            $id = new Uuid();
            $connection->executeStatement(
                "INSERT INTO libraries (id, name, slug, path, type, sort_order, created_at, updated_at, filesystem_type)
                 VALUES (?, 'Constraint', ?, '/media/constraint', 'music', 0, now(), now(), 'local')",
                [$id->toString(), 'constraint-' . bin2hex(random_bytes(4))],
            );
            try {
                $connection->executeStatement("UPDATE libraries SET scan_status = 'scanning' WHERE id = ?", [$id->toString()]);
                self::fail('A scanning status without a claim must violate chk_libraries_scan_claim.');
            } catch (DriverException $violation) {
                self::assertSame('23514', $violation->getSQLState());
                self::assertStringContainsString('chk_libraries_scan_claim', $violation->getMessage());
            }
        } finally {
            $connection->rollBack();
        }
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

    /** Moves the claim's lease into the past, as if its scan stopped renewing it. */
    private function lapse(): void
    {
        self::assertSame(1, $this->observer->executeStatement(
            "UPDATE libraries SET scan_claim_expires_at = clock_timestamp() - interval '1 second' WHERE id = ? AND scan_claim_id IS NOT NULL",
            [$this->library->getId()->toString()],
        ));
    }

    /** @return array{scan_status: ?string, scan_claim_id: ?string, scan_claim_expires_at: ?string, last_scan: ?string} */
    private function row(): array
    {
        $row = $this->observer->fetchAssociative(
            'SELECT scan_status, scan_claim_id, scan_claim_expires_at, last_scan FROM libraries WHERE id = ?',
            [$this->library->getId()->toString()],
        );
        self::assertIsArray($row);

        /** @var array{scan_status: ?string, scan_claim_id: ?string, scan_claim_expires_at: ?string, last_scan: ?string} $row */
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
