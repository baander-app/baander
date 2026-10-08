<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Repository\LibraryRepository;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Fixtures\Library\LibraryScanClaimRace;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * Starting a scan claims the library with one conditional update. Two sessions
 * race on PostgreSQL: the second waits on the row lock of the first and, once that commits,
 * updates nothing.
 */
final class LibraryScanClaimTest extends TestCase
{
    private Connection $writer;
    private Connection $observer;
    private EntityManager $manager;
    private LibraryRepository $libraries;
    private string $schema;
    private Library $library;

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
    }

    public function testOfTwoConcurrentClaimsExactlyOneSucceeds(): void
    {
        self::assertNull($this->scanStatus(), 'A library that never scanned has no status.');

        $this->writer->beginTransaction();
        self::assertTrue($this->libraries->claimScan($this->library->getId()));
        // The first claim holds the row lock until it commits; the second session waits on it.
        $child = $this->startChild();
        $this->awaitLockWait($child);
        $this->writer->commit();

        self::assertSame(['claimed' => false], $this->finish($child));
        self::assertSame('scanning', $this->scanStatus());
    }

    public function testAClaimRolledBackLeavesTheLibraryClaimableForTheWaitingSession(): void
    {
        $this->writer->beginTransaction();
        self::assertTrue($this->libraries->claimScan($this->library->getId()));
        $child = $this->startChild();
        $this->awaitLockWait($child);
        $this->writer->rollBack();

        self::assertSame(['claimed' => true], $this->finish($child));
        self::assertSame('scanning', $this->scanStatus());
    }

    public function testAFinishedOrFailedScanCanBeClaimedAgainButARunningOneCannot(): void
    {
        foreach (['completed', 'failed'] as $status) {
            $this->observer->executeStatement('UPDATE libraries SET scan_status = ? WHERE id = ?', [$status, $this->library->getId()->toString()]);
            self::assertTrue($this->libraries->claimScan($this->library->getId()), $status);
            self::assertFalse($this->libraries->claimScan($this->library->getId()), $status);
        }
    }

    public function testReleaseMarksAClaimedScanFailedAndLeavesOtherStatusesAlone(): void
    {
        self::assertFalse($this->libraries->releaseScanClaim($this->library->getId()), 'No claim to release.');
        self::assertNull($this->scanStatus());

        $this->libraries->claimScan($this->library->getId());
        self::assertTrue($this->libraries->releaseScanClaim($this->library->getId()));
        self::assertSame('failed', $this->scanStatus());
        self::assertFalse($this->libraries->releaseScanClaim($this->library->getId()));

        $this->observer->executeStatement("UPDATE libraries SET scan_status = 'completed' WHERE id = ?", [$this->library->getId()->toString()]);
        self::assertFalse($this->libraries->releaseScanClaim($this->library->getId()));
        self::assertSame('completed', $this->scanStatus());
    }

    public function testAClaimRefreshesALibraryTheSessionAlreadyLoaded(): void
    {
        $loaded = $this->libraries->findByUuid($this->library->getId());
        self::assertNotNull($loaded);
        self::assertNull($loaded->getDiscoveryStatus());

        self::assertTrue($this->libraries->claimScan($this->library->getId()));

        self::assertSame('scanning', $this->libraries->findByUuid($this->library->getId())?->getDiscoveryStatus());
    }

    private function scanStatus(): ?string
    {
        $status = $this->observer->fetchOne('SELECT scan_status FROM libraries WHERE id = ?', [$this->library->getId()->toString()]);

        return $status === null ? null : (string) $status;
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
