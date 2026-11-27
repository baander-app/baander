<?php

declare(strict_types=1);

namespace App\Tests\Integration\Library;

use App\Kernel;
use App\Library\Application\LibraryDiscovery;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\MovieScanner;
use App\Library\Application\MusicScanner;
use App\Library\Application\Service\LibraryScanLease;
use App\Library\Application\TvScanner;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Doctrine\Repository\LibraryFileIndexRepository;
use App\Library\Infrastructure\Doctrine\Repository\LibraryRepository;
use App\Library\Infrastructure\Scanner\DirectoryScanner;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Fixtures\Messaging\CancelAtCheckpoint;
use App\Tests\Support\FinalSchemaBootstrap;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;

#[SkipDatabaseRollback]
final class TvLibraryScanTest extends TestCase
{
    private Kernel $kernel;
    private EntityManagerInterface $manager;
    private Connection $connection;
    private LibraryFileIndexRepository $index;
    private LibraryRepository $libraries;
    private Library $library;
    private LibraryDiscovery $discovery;
    private string $schema;
    private string $root;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Requires DATABASE_URL and OUTBOX_TEST_DATABASE_URL selecting the same disposable PostgreSQL database.');
        }
        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $this->manager = $container->get('doctrine')->getManager();
        $this->connection = $this->manager->getConnection();
        $this->schema = 'tv_scan_' . bin2hex(random_bytes(8));
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        FinalSchemaBootstrap::bootstrap($this->connection);
        $this->index = new LibraryFileIndexRepository($this->manager);
        $clock = new NativeClock();
        $this->libraries = new LibraryRepository($this->manager, $clock);
        $this->root = sys_get_temp_dir() . '/baander-tv-library-' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir([$this->root . '/Show/Season 01', $this->root . '/Show/Specials']);
        $this->library = Library::create('TV scan', new LibrarySlug('tv-scan'), new LibraryPath($this->root), LibraryType::TvShow, FilesystemType::Local);
        $this->libraries->save($this->library);
        $logger = new NullLogger();
        $directory = new DirectoryScanner($logger);
        $transaction = $container->get(TransactionPortInterface::class);
        $cancellation = new CancelAtCheckpoint();
        $movieScanner = new MovieScanner($directory, $this->index, $logger, $cancellation, $transaction);
        $this->discovery = new LibraryDiscovery(
            new MusicScanner($directory, $this->index, $logger, $cancellation, $transaction),
            $movieScanner,
            new TvScanner($movieScanner),
            new EventDispatcher(),
            $logger,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->manager)) {
            $this->manager->clear();
        }
        if (isset($this->schema)) {
            while ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->connection->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            $this->connection->executeStatement('SET search_path TO public');
        }
        if (isset($this->root)) {
            (new Filesystem())->remove($this->root);
        }
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        parent::tearDown();
    }

    public function testTvDiscoveryIndexesSidecarsPublishesTheirChangesAndRetainsMissingObservations(): void
    {
        $episode = $this->root . '/Show/Season 01/Show - S01E01.mkv';
        $special = $this->root . '/Show/Specials/Show - S00E01.mp4';
        $nfo = $this->root . '/Show/tvshow.nfo';
        foreach ([$episode => 'episode', $special => 'special', $nfo => '<tvshow/>'] as $path => $contents) {
            self::assertNotFalse(file_put_contents($path, $contents));
        }
        $published = [];
        $publish = function (string $directory, array $files) use (&$published): void {
            self::assertTrue($this->connection->isTransactionActive());
            $paths = array_map(static fn (DiscoveredFile $file): string => $file->absolutePath, $files);
            self::assertCount(count($files), $this->index->findAvailableHashesByPaths($this->library->getId(), $paths));
            $published[$directory] = array_map(static fn (DiscoveredFile $file): string => $file->relativePath, $files);
        };

        $first = $this->discovery->discover($this->library, false, $this->lease(), $publish);

        self::assertSame(3, $first->filesProcessed);
        self::assertCount(3, $published);
        self::assertSame(['Show/tvshow.nfo'], $published[dirname($nfo)]);
        self::assertSame(['Show/Season 01/Show - S01E01.mkv'], $published[dirname($episode)]);
        self::assertSame(['Show/Specials/Show - S00E01.mp4'], $published[dirname($special)]);

        $published = [];
        self::assertNotFalse(file_put_contents($nfo, '<tvshow><title>Edited</title></tvshow>'));
        $second = $this->discovery->discover($this->library, false, $this->lease(), $publish);

        self::assertSame(1, $second->filesProcessed);
        self::assertSame([dirname($nfo) => ['Show/tvshow.nfo']], $published);
        self::assertTrue(unlink($special));
        $published = [];
        $third = $this->discovery->discover($this->library, false, $this->lease(), $publish);

        self::assertSame(0, $third->filesProcessed);
        self::assertSame([], $published);
        self::assertSame([$episode => true, $special => false, $nfo => true], $this->index->findAvailabilityByPaths($this->library->getId(), [$episode, $special, $nfo]));
        self::assertCount(3, $this->index->findIndexedPathsByLibrary($this->library->getId()));
    }

    private function lease(): LibraryScanLease
    {
        $claim = new Uuid();
        self::assertTrue($this->libraries->claimScan($this->library->getId(), $claim, 900)->claimed);

        return new LibraryScanLease($this->libraries, new NativeClock(), $this->library, $claim, 900, 60);
    }
}
