<?php

declare(strict_types=1);

namespace App\Tests\Integration\Library;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Application\Port\MetadataContentReaderPortInterface;
use App\Kernel;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Doctrine\Repository\LibraryFileIndexRepository;
use App\Library\Infrastructure\Doctrine\Repository\LibraryRepository;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Fixtures\Library\LibraryDiscoveryObservationRace as Race;
use App\Tests\Fixtures\Library\LibraryScanClaimRace;
use App\Tests\Support\FinalSchemaBootstrap;
use Closure;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[SkipDatabaseRollback]
final class LibraryDiscoveryObservationTest extends TestCase
{
    private Kernel $kernel;
    private EntityManagerInterface $manager;
    private Connection $writer;
    private Connection $observer;
    private LibraryFileIndexRepository $index;
    private string $schema;
    private string $root;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Requires DATABASE_URL and OUTBOX_TEST_DATABASE_URL selecting the same disposable PostgreSQL database.');
        }
        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();
        $this->manager = $this->kernel->getContainer()->get('test.service_container')->get('doctrine')->getManager();
        $this->writer = $this->manager->getConnection();
        self::assertSame(0, $this->writer->getTransactionNestingLevel());
        $this->schema = 'discovery_observation_' . bin2hex(random_bytes(8));
        $this->writer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->writer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        $this->observer = LibraryScanClaimRace::connect($this->schema);
        self::assertSame($this->writer->fetchOne('SELECT current_database()'), $this->observer->fetchOne('SELECT current_database()'));
        self::assertNotSame($this->writer->fetchOne('SELECT pg_backend_pid()'), $this->observer->fetchOne('SELECT pg_backend_pid()'));
        FinalSchemaBootstrap::bootstrap($this->writer);
        $this->index = new LibraryFileIndexRepository($this->manager);
        $this->root = sys_get_temp_dir() . '/baander-observation-' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->root . '/Movie');
    }

    protected function tearDown(): void
    {
        if (isset($this->manager)) {
            $this->manager->clear();
        }
        foreach ([$this->writer ?? null, $this->observer ?? null] as $connection) {
            while ($connection?->isTransactionActive()) {
                $connection->rollBack();
            }
            $connection?->executeQuery('SELECT pg_advisory_unlock_all()')->free();
        }
        if (isset($this->schema)) {
            $this->writer->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            $this->writer->executeStatement('SET search_path TO public');
        }
        if (isset($this->observer)) {
            $this->observer->close();
        }
        if (isset($this->root)) {
            (new Filesystem())->remove($this->root);
        }
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string, LibraryType, bool}> */
    public static function orderedWriters(): iterable
    {
        foreach ([false, true] as $rollback) {
            foreach ([LibraryType::Movie, LibraryType::Music] as $type) {
                yield $type->value . ($rollback ? ' rollback' : ' commit') => ['scan', $type, $rollback];
            }
            foreach (['unavailable', 'remove'] as $kind) {
                yield $kind . ($rollback ? ' rollback' : ' commit') => [$kind, LibraryType::Movie, $rollback];
            }
        }
    }

    #[DataProvider('orderedWriters')]
    public function testActualImportHoldsObservationUntilCommitAndWritersLockReversedInputInPathOrder(string $kind, LibraryType $scannerType, bool $rollback): void
    {
        $library = $this->library($scannerType);
        $extension = $scannerType === LibraryType::Music ? 'flac' : 'mp4';
        $x = $scannerType === LibraryType::Music ? $this->audio('a.flac') : $this->video('a.mp4');
        $y = $this->root . '/Movie/z.' . $extension;
        self::assertTrue(copy($x, $y));
        // Put Y physically before X too: the bulk contender forces a sequential scan,
        // making an unordered mutation encounter Y first regardless of index selection.
        $this->seed($library, [$y, $x]);
        $message = $this->message($library, [$x], $scannerType);
        $table = $scannerType === LibraryType::Music ? 'songs' : 'videos';
        $child = null;
        $outcome = [];
        $oldHash = $message->files[0]->hash;
        $transaction = $this->transaction(after: function () use ($library, $kind, $x, $y, $rollback, $oldHash, $table, &$child): void {
            if ((int) $this->writer->fetchOne('SELECT count(*) FROM ' . $table) === 0) {
                // Music resolves its album in an earlier transaction. Hold the actual
                // song write, after the entire song batch acquired its observation locks.
                return;
            }
            self::assertSame(1, (int) $this->writer->fetchOne('SELECT count(*) FROM ' . $table));
            self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM ' . $table));
            if ($kind === 'scan') {
                self::assertNotFalse(file_put_contents($x, 'replacement observation', FILE_APPEND));
            }
            $child = Race::start($this->schema, $kind, ['library' => $library->getId()->toString(), 'paths' => [$y, $x]]);
            Race::awaitLockWait($this->observer, $child, (int) $this->writer->fetchOne('SELECT pg_backend_pid()'));
            self::assertSame($oldHash, $this->observer->fetchOne('SELECT hash FROM library_file_index WHERE path = ?', [$x]));
            self::assertTrue($this->observer->fetchOne('SELECT available FROM library_file_index WHERE path = ?', [$x]));
            // SHARE is compatible with the import, but conflicts with an unsorted writer
            // that acquired Y before blocking on X. NOWAIT makes that defect deterministic.
            self::assertSame($y, $this->writer->fetchOne('SELECT path FROM library_file_index WHERE path = ? FOR SHARE NOWAIT', [$y]));
            if ($rollback) {
                throw new \RuntimeException('Rollback observation fixture.');
            }
        });
        try {
            ($this->consumer($transaction))($message);
            self::assertFalse($rollback);
        } catch (\RuntimeException $error) {
            self::assertTrue($rollback);
            self::assertSame('Rollback observation fixture.', $error->getMessage());
        } finally {
            if ($child !== null) {
                $outcome = Race::finish($child);
            }
        }
        self::assertNotNull($child);
        self::assertSame($rollback ? 0 : 1, (int) $this->observer->fetchOne('SELECT count(*) FROM ' . $table));
        if ($kind === 'scan') {
            self::assertSame([$y, $x], $outcome['published']);
            self::assertSame([$y, $x], $outcome['result']);
            self::assertNotSame($oldHash, $this->observer->fetchOne('SELECT hash FROM library_file_index WHERE path = ?', [$x]));
        } elseif ($kind === 'unavailable') {
            self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM library_file_index WHERE available'));
        } else {
            self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM library_file_index'));
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function writerOutcomes(): iterable
    {
        yield 'committed replacement' => [false];
        yield 'rolled back replacement' => [true];
    }

    #[DataProvider('writerOutcomes')]
    public function testScannerFinishingBeforeTheFinalConsumerGuardSkipsOnlyCommittedReplacement(bool $rollback): void
    {
        $library = $this->library(LibraryType::Movie);
        $path = $this->video('a.mp4');
        $this->seed($library, [$path]);
        $older = $this->message($library, [$path]);
        $this->video('a.mp4', 'blue');
        $child = null;
        $outcome = [];
        $transaction = $this->transaction(after: function () use ($older, $rollback, &$child): void {
            $child = Race::start($this->schema, 'consume', $this->payload($older));
            Race::awaitLockWait($this->observer, $child, (int) $this->writer->fetchOne('SELECT pg_backend_pid()'));
            self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM movies'));
            if ($rollback) {
                throw new \RuntimeException('Rollback observation fixture.');
            }
        });
        try {
            Race::scanner($this->writer, $library, [$path], $transaction)->scan($library, rescan: true);
            self::assertFalse($rollback);
        } catch (\RuntimeException $error) {
            self::assertTrue($rollback);
            self::assertSame('Rollback observation fixture.', $error->getMessage());
        } finally {
            if ($child !== null) {
                $outcome = Race::finish($child);
            }
        }
        self::assertNotNull($child);
        self::assertSame(['movies' => $rollback ? 1 : 0, 'videos' => $rollback ? 1 : 0], $outcome);
        if (!$rollback) {
            // A mixed old/current message still imports the current observation, and replay
            // remains idempotent after the stale candidate is discarded.
            $current = $this->message($library, [$path]);
            $mixed = new FilesDiscovered($library->getId(), LibraryType::Movie->value, dirname($path), [$older->files[0], $current->files[0]]);
            ($this->consumer())($mixed);
            ($this->consumer())($current);
            self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM videos'));
            self::assertSame($current->files[0]->hash, $this->observer->fetchOne('SELECT hash FROM videos'));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function bulkWriters(): iterable
    {
        yield 'unavailable' => ['unavailable'];
        yield 'remove' => ['remove'];
    }

    #[DataProvider('bulkWriters')]
    public function testBulkWriterLocksAllChunksAndDoesNotMutateAnInitiallyAbsentPath(string $kind): void
    {
        $library = $this->library(LibraryType::Movie);
        $paths = [];
        for ($i = 0; $i < 1002; ++$i) {
            $paths[] = $this->root . '/Movie/' . sprintf('%04d.mp4', $i);
        }
        $this->writer->transactional(function () use ($library, $paths): void {
            foreach ($paths as $path) {
                $this->index->upsert($library->getId(), $path, 'observed', 1, 'mp4', 1);
            }
        });
        // Include unavailable rows in the lock set too.
        $this->index->markUnavailableByPaths($library->getId(), [$paths[1001]]);
        $absent = $this->root . '/Movie/1002.mp4';
        // Pause the first mutation statement after every prelock SELECT has returned.
        // The trigger is local to this disposable schema and uses a session-held barrier.
        $this->writer->executeStatement(<<<'SQL'
CREATE FUNCTION observation_mutation_barrier() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM pg_advisory_xact_lock(891234, 701);
    RETURN NULL;
END
$$
SQL);
        $this->writer->executeStatement('CREATE TRIGGER observation_mutation_barrier BEFORE UPDATE OR DELETE ON library_file_index FOR EACH STATEMENT EXECUTE FUNCTION observation_mutation_barrier()');
        $this->observer->executeQuery('SELECT pg_advisory_lock(891234, 701)')->free();
        $child = Race::start($this->schema, $kind, ['library' => $library->getId()->toString(), 'paths' => array_reverse([...$paths, $absent])]);
        try {
            Race::awaitLockWait($this->writer, $child, (int) $this->observer->fetchOne('SELECT pg_backend_pid()'));
            // A row in the second chunk is already locked before the first mutation.
            $this->writer->beginTransaction();
            $this->writer->createSavepoint('lock_probe');
            try {
                $this->writer->fetchOne('SELECT path FROM library_file_index WHERE path = ? FOR SHARE NOWAIT', [$paths[1001]]);
                self::fail('The second chunk must be locked before mutation starts.');
            } catch (\Doctrine\DBAL\Exception\DriverException $error) {
                self::assertSame('55P03', $error->getSQLState());
                $this->writer->rollbackSavepoint('lock_probe');
            } finally {
                $this->writer->rollBack();
            }
            $this->observer->executeStatement(
                'INSERT INTO library_file_index (id, library_id, path, hash, size, extension, modified_at, discovered_at, available) VALUES (?, ?, ?, ?, 1, ?, 1, now(), true)',
                [(new Uuid())->toString(), $library->getId()->toString(), $absent, 'newly-observed', 'mp4'],
            );
        } finally {
            $this->observer->executeQuery('SELECT pg_advisory_unlock(891234, 701)')->free();
            $outcome = Race::finish($child);
        }
        self::assertSame(['done' => true], $outcome);
        self::assertSame(['hash' => 'newly-observed', 'available' => true], $this->observer->fetchAssociative('SELECT hash, available FROM library_file_index WHERE path = ?', [$absent]));
        self::assertSame($kind === 'remove' ? 1 : 1003, (int) $this->observer->fetchOne('SELECT count(*) FROM library_file_index'));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM library_file_index WHERE available'));
    }

    public function testRealProbeFailureLeavesNoParentAndRestoredSourceImportsOnRetry(): void
    {
        $library = $this->library(LibraryType::Movie);
        $path = $this->video('a.mp4');
        $bytes = file_get_contents($path);
        self::assertIsString($bytes);
        $this->seed($library, [$path]);
        $message = $this->message($library, [$path]);
        self::assertNotFalse(file_put_contents($path, 'temporarily unreadable video container'));
        try {
            ($this->consumer())($message);
            self::fail('The actual FFprobe adapter must reject an invalid video.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString(basename($path), $error->getMessage());
        }
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM movies'));
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM videos'));
        self::assertNotFalse(file_put_contents($path, $bytes));
        ($this->consumer())($message);
        ($this->consumer())($message);
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM movies'));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM videos'));
        self::assertSame(['width' => 64, 'height' => 64], $this->observer->fetchAssociative('SELECT width, height FROM videos'));
    }

    public function testMetadataReadExceptionLeavesNoParentAndTheCurrentMessageCanRetry(): void
    {
        $library = $this->library(LibraryType::Music);
        $path = $this->audio('a.flac');
        $this->seed($library, [$path]);
        $message = $this->message($library, [$path], LibraryType::Music);
        $reader = $this->createMock(MetadataContentReaderPortInterface::class);
        $reader->expects($this->once())->method('readMetadata')->with($path)->willThrowException(new \RuntimeException('Transient metadata read failure.'));
        try {
            ($this->consumer(metadataReader: $reader))($message);
            self::fail('Metadata read exceptions must remain retryable.');
        } catch (\RuntimeException $error) {
            self::assertSame('Transient metadata read failure.', $error->getMessage());
        }
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM albums'));
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM songs'));
        ($this->consumer())($message);
        ($this->consumer())($message);
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM albums'));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM songs'));
        self::assertSame('Recovered track', $this->observer->fetchOne('SELECT title FROM songs'));
    }

    private function library(LibraryType $type): Library
    {
        $library = Library::create('Observation fixture', new LibrarySlug('observation-fixture'), new LibraryPath($this->root), $type, FilesystemType::Local);
        (new LibraryRepository($this->manager, new NativeClock()))->save($library);
        return $library;
    }

    private function video(string $name, string $color = 'black'): string
    {
        $path = $this->root . '/Movie/' . $name;
        $process = new Process(['ffmpeg', '-y', '-nostdin', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=c=' . $color . ':s=64x64:r=5:d=0.4', '-an', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-f', 'mp4', $path]);
        $process->setTimeout(30);
        $process->mustRun();
        clearstatcache(true, $path);
        return $path;
    }

    private function audio(string $name): string
    {
        $path = $this->root . '/Movie/' . $name;
        $process = new Process(['ffmpeg', '-y', '-nostdin', '-loglevel', 'error', '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono', '-t', '0.1', '-c:a', 'flac', '-metadata', 'title=Recovered track', '-metadata', 'album=Recovered album', $path]);
        $process->setTimeout(30);
        $process->mustRun();
        clearstatcache(true, $path);
        return $path;
    }

    /** @param list<string> $paths */
    private function seed(Library $library, array $paths): void
    {
        foreach ($paths as $path) {
            $this->index->upsert($library->getId(), $path, (string) hash_file('xxh3', $path), (int) filesize($path), pathinfo($path, PATHINFO_EXTENSION), (int) filemtime($path));
        }
    }

    /** @param list<string> $paths */
    private function message(Library $library, array $paths, LibraryType $type = LibraryType::Movie): FilesDiscovered
    {
        return new FilesDiscovered($library->getId(), $type->value, dirname($paths[0]), array_map(static fn (string $path): DiscoveredFile => new DiscoveredFile($path, basename($path), pathinfo($path, PATHINFO_EXTENSION), (int) filesize($path), (int) filemtime($path), (string) hash_file('xxh3', $path)), $paths));
    }

    /** @return array<string, mixed> */
    private function payload(FilesDiscovered $message): array
    {
        return ['library' => $message->libraryId->toString(), 'type' => $message->libraryType, 'directory' => $message->directory, 'files' => array_map(static fn (DiscoveredFile $file): array => get_object_vars($file), $message->files)];
    }

    private function transaction(?Closure $after = null): TransactionPortInterface
    {
        $inner = $this->kernel->getContainer()->get('test.service_container')->get(TransactionPortInterface::class);
        return new class($inner, $after) implements TransactionPortInterface {
            public function __construct(private TransactionPortInterface $inner, private ?Closure $after) {}
            public function run(callable $operation): mixed
            {
                return $this->inner->run(function () use ($operation): mixed {
                    $result = $operation();
                    ($this->after ?? static function (): void {})();
                    return $result;
                });
            }
        };
    }

    private function consumer(?TransactionPortInterface $transaction = null, ?MetadataContentReaderPortInterface $metadataReader = null): FilesDiscoveredHandler
    {
        $handler = $this->kernel->getContainer()->get('test.service_container')->get(FilesDiscoveredHandler::class);
        if ($transaction === null && $metadataReader === null) {
            return $handler;
        }
        $reflection = new \ReflectionClass(FilesDiscoveredHandler::class);
        $arguments = [];
        foreach ($reflection->getConstructor()->getParameters() as $parameter) {
            $arguments[] = match ($parameter->getName()) {
                'transaction' => $transaction ?? $reflection->getProperty('transaction')->getValue($handler),
                'metadataReader' => $metadataReader ?? $reflection->getProperty('metadataReader')->getValue($handler),
                default => $reflection->getProperty($parameter->getName())->getValue($handler),
            };
        }
        return $reflection->newInstanceArgs($arguments);
    }
}
