<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Kernel;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\MovieScanner;
use App\Library\Application\MusicScanner;
use App\Library\Domain\Event\LibraryDirectoryIndexed;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Doctrine\Repository\LibraryFileIndexRepository;
use App\Library\Infrastructure\Doctrine\Repository\LibraryRepository;
use App\Library\Infrastructure\Messaging\LibraryDirectoryOutboxEventDispatcher;
use App\Library\Infrastructure\Scanner\DirectoryScanner;
use App\Shared\Application\Port\JobCancellationCheckpointInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Event\Outbox\OutboxEventDispatcherInterface;
use App\Shared\Domain\Event\Outbox\OutboxRelayException;
use App\Shared\Domain\Event\Outbox\OutboxRepository;
use App\Shared\Domain\Event\Outbox\OutboxSubscriber;
use App\Shared\Domain\Event\Outbox\RelayOutboxCommand;
use App\Shared\Domain\Event\Outbox\RelayOutboxHandler;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Support\FinalSchemaBootstrap;
use Closure;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Process\Process;

#[SkipDatabaseRollback]
final class LibraryDirectoryPublicationTest extends TestCase
{
    private Kernel $kernel;
    private EntityManagerInterface $manager;
    private Connection $writer;
    private Connection $observer;
    private string $schema;
    private string $root;
    private LibraryFileIndexRepository $index;
    private EventDispatcher $events;
    private InMemoryTransport $transport;
    private RelayOutboxHandler $relay;
    private bool $failSend = false;
    private bool $expireAfterSend = false;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Requires disposable PostgreSQL selected by DATABASE_URL and OUTBOX_TEST_DATABASE_URL.');
        }
        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $this->manager = $container->get('doctrine')->getManager();
        $this->writer = $this->manager->getConnection();
        self::assertSame(0, $this->writer->getTransactionNestingLevel(), 'Commit visibility requires no enclosing test transaction.');
        $this->observer = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
        self::assertSame($this->writer->fetchOne('SELECT current_database()'), $this->observer->fetchOne('SELECT current_database()'));
        self::assertNotSame($this->writer->fetchOne('SELECT pg_backend_pid()'), $this->observer->fetchOne('SELECT pg_backend_pid()'));
        $this->schema = 'directory_publication_' . bin2hex(random_bytes(8));
        $this->writer->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->writer, $this->observer] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
            $connection->executeStatement("SET TIME ZONE 'UTC'");
        }
        FinalSchemaBootstrap::bootstrap($this->writer);
        $this->root = sys_get_temp_dir() . '/baander-directory-publication-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0777, true));
        $this->index = new LibraryFileIndexRepository($this->manager);
        $this->events = new EventDispatcher();
        $this->events->addListener(LibraryDirectoryIndexed::class, new OutboxSubscriber(new OutboxRepository($this->writer), new NullLogger()));
        $this->transport = new InMemoryTransport();
        $sender = $this->createStub(SenderInterface::class);
        $sender->method('send')->willReturnCallback(function (Envelope $envelope): Envelope {
            if ($this->failSend) {
                throw new \RuntimeException('Transport unavailable.');
            }
            $sent = $this->transport->send($envelope);
            if ($this->expireAfterSend) {
                $this->observer->executeStatement("UPDATE domain_event_outbox SET lease_until = clock_timestamp() - interval '1 second' WHERE relayed_at IS NULL");
            }
            return $sent;
        });
        $bus = new MessageBus([new SendMessageMiddleware(new SendersLocator(
            [FilesDiscovered::class => ['async']],
            new ServiceLocator(['async' => static fn (): SenderInterface => $sender]),
        ))]);
        $inner = $this->createMock(OutboxEventDispatcherInterface::class);
        $inner->expects($this->never())->method('dispatch');
        $this->relay = new RelayOutboxHandler(new OutboxRepository($this->observer), new LibraryDirectoryOutboxEventDispatcher($inner, $bus), new NullLogger());
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

    /** @return iterable<string, array{LibraryType}> */
    public static function libraryTypes(): iterable
    {
        yield 'music' => [LibraryType::Music];
        yield 'movie' => [LibraryType::Movie];
    }

    #[DataProvider('libraryTypes')]
    public function testIndexAndIntentBecomeVisibleTogetherBeforeRelayCanPublish(LibraryType $type): void
    {
        $library = $this->library($type);
        $path = $this->file($type);
        $capture = $this->capture($library);
        $scan = $this->scanner($type)->scan($library, publishDirectory: function (string $directory, array $files) use ($capture): void {
            $capture($directory, $files);
            self::assertSame(1, $this->rowCount($this->writer, 'library_file_index'));
            self::assertSame(1, $this->rowCount($this->writer, 'domain_event_outbox'));
            self::assertSame(0, $this->rowCount($this->observer, 'library_file_index'));
            self::assertSame(0, $this->rowCount($this->observer, 'domain_event_outbox'));
            self::assertSame(0, ($this->relay)(new RelayOutboxCommand()));
            self::assertCount(0, $this->transport->getSent());
        });
        self::assertSame(1, $scan->filesProcessed);
        self::assertSame(0, $this->writer->getTransactionNestingLevel());
        self::assertSame(1, $this->rowCount($this->observer, 'library_file_index'));
        self::assertSame(1, $this->rowCount($this->observer, 'domain_event_outbox'));
        self::assertSame(1, ($this->relay)(new RelayOutboxCommand()));
        $message = $this->discovery(0, $library, $path);
        self::assertSame(hash_file('xxh3', $path), $message->files[0]->hash);
        self::assertSame(filesize($path), $message->files[0]->size);
        self::assertSame(filemtime($path), $message->files[0]->modifiedAt);
        self::assertSame('Album/' . basename($path), $message->files[0]->relativePath);
    }

    /** @return iterable<string, array{string}> */
    public static function writeFailures(): iterable
    {
        yield 'capture' => ['capture'];
        yield 'second index write' => ['index'];
    }

    #[DataProvider('writeFailures')]
    public function testDirectoryWriteFailureRollsBackAndUnchangedRetryCapturesIt(string $failure): void
    {
        $library = $this->library(LibraryType::Music);
        $this->file(LibraryType::Music);
        $this->file(LibraryType::Music, 'Album/02.flac');
        $table = $failure === 'capture' ? 'domain_event_outbox' : 'library_file_index';
        $condition = $failure === 'capture' ? 'true' : '(SELECT count(*) FROM library_file_index) > 0';
        $this->writer->executeStatement("CREATE FUNCTION reject_directory_write() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF $condition THEN RAISE EXCEPTION 'directory write rejected'; END IF; RETURN NEW; END \$\$");
        $this->writer->executeStatement('CREATE TRIGGER reject_directory_write BEFORE INSERT ON ' . $table . ' FOR EACH ROW EXECUTE FUNCTION reject_directory_write()');
        try {
            $this->scanner(LibraryType::Music)->scan($library, publishDirectory: $this->capture($library));
            self::fail('A failed write must abort the directory transaction.');
        } catch (\Doctrine\DBAL\Exception $error) {
            self::assertStringContainsString('directory write rejected', $error->getMessage());
        }
        self::assertSame(0, $this->rowCount($this->observer, 'library_file_index'));
        self::assertSame(0, $this->rowCount($this->observer, 'domain_event_outbox'));
        self::assertSame(0, ($this->relay)(new RelayOutboxCommand()));
        $this->writer->executeStatement('DROP TRIGGER reject_directory_write ON ' . $table);
        $scan = $this->scanner(LibraryType::Music)->scan($library, publishDirectory: $this->capture($library));
        self::assertSame(2, $scan->filesProcessed);
        self::assertSame(2, $this->rowCount($this->observer, 'library_file_index'));
        self::assertSame(1, ($this->relay)(new RelayOutboxCommand()));
        self::assertCount(2, $this->transport->getSent()[0]->getMessage()->files);
    }

    public function testAnEarlierDirectoryRemainsCommittedWhenLaterCaptureFails(): void
    {
        $library = $this->library(LibraryType::Music);
        $this->file(LibraryType::Music, 'First/01.flac');
        $this->file(LibraryType::Music, 'Second/02.flac');
        $capture = $this->capture($library);
        $accepted = null;
        try {
            $this->scanner(LibraryType::Music)->scan($library, publishDirectory: function (string $directory, array $files) use ($capture, &$accepted): void {
                if ($accepted !== null) {
                    throw new \RuntimeException('Later capture failed.');
                }
                $capture($directory, $files);
                $accepted = $files[0]->absolutePath;
            });
            self::fail('The second directory capture must fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('Later capture failed.', $error->getMessage());
        }
        self::assertSame([$accepted], $this->observer->fetchFirstColumn('SELECT path FROM library_file_index'));
        self::assertSame(1, $this->rowCount($this->observer, 'domain_event_outbox'));
        self::assertSame(1, ($this->relay)(new RelayOutboxCommand()));
        self::assertSame(1, $this->scanner(LibraryType::Music)->scan($library, publishDirectory: $this->capture($library))->filesProcessed);
        self::assertSame(2, $this->rowCount($this->observer, 'library_file_index'));
    }

    public function testTransportFailureLeavesCommittedIntentForSuccessfulRetry(): void
    {
        $library = $this->library(LibraryType::Music);
        $path = $this->file(LibraryType::Music);
        $this->scanner(LibraryType::Music)->scan($library, publishDirectory: $this->capture($library));
        $this->failSend = true;
        $this->assertRelayFails();
        self::assertCount(0, $this->transport->getSent());
        self::assertSame(1, $this->rowCount($this->observer, 'library_file_index'));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT attempts FROM domain_event_outbox'));
        self::assertNull($this->observer->fetchOne("SELECT relayed_at FROM domain_event_outbox WHERE event_name = 'library.directory_indexed'"));
        self::assertSame(0, $this->scanner(LibraryType::Music)->scan($library, publishDirectory: $this->capture($library))->filesProcessed);
        $this->failSend = false;
        $this->observer->executeStatement('UPDATE domain_event_outbox SET next_attempt_at = clock_timestamp()');
        self::assertSame(1, ($this->relay)(new RelayOutboxCommand()));
        $this->discovery(0, $library, $path);
        self::assertNotNull($this->observer->fetchOne("SELECT relayed_at FROM domain_event_outbox WHERE event_name = 'library.directory_indexed'"));
    }

    /** @return iterable<string, array{LibraryType, bool}> */
    public static function missingObservations(): iterable
    {
        foreach ([LibraryType::Music, LibraryType::Movie] as $type) {
            yield $type->value . ' new' => [$type, false];
            yield $type->value . ' unavailable' => [$type, true];
        }
    }

    #[DataProvider('missingObservations')]
    public function testImmediateMissingConsumerObservationSurvivesAndUnchangedReturnIsPublished(LibraryType $type, bool $previouslyUnavailable): void
    {
        $library = $this->library($type);
        $path = $type === LibraryType::Music ? $this->audioFile() : $this->file($type);
        if ($previouslyUnavailable) {
            $this->scanner($type)->scan($library);
            $this->index->markUnavailableByPaths($library->getId(), [$path]);
        }
        $this->scanner($type)->scan($library, publishDirectory: $this->capture($library));
        self::assertSame(1, ($this->relay)(new RelayOutboxCommand()));
        $message = $this->discovery(0, $library, $path);
        self::assertTrue(rename($path, $path . '.absent'));
        $consumer = $this->kernel->getContainer()->get('test.service_container')->get(FilesDiscoveredHandler::class);
        $consumer($message);
        self::assertSame([$path => false], $this->index->findAvailabilityByPaths($library->getId(), [$path]));
        self::assertSame(0, $this->rowCount($this->observer, 'songs'));
        self::assertSame(0, $this->rowCount($this->observer, 'videos'));
        self::assertTrue(rename($path . '.absent', $path));
        self::assertSame($message->files[0]->hash, hash_file('xxh3', $path));
        self::assertSame(1, $this->scanner($type)->scan($library, publishDirectory: $this->capture($library))->filesProcessed);
        self::assertSame(1, ($this->relay)(new RelayOutboxCommand()));
        $returned = $this->discovery(1, $library, $path);
        self::assertEquals($message, $returned);
        if ($type === LibraryType::Music) {
            $consumer($returned);
            self::assertSame(1, $this->rowCount($this->observer, 'songs'));
            self::assertSame($path, $this->observer->fetchOne('SELECT path FROM songs'));
        }
        self::assertSame([$path => true], $this->index->findAvailabilityByPaths($library->getId(), [$path]));
    }

    public function testAcceptedSendWithLostAcknowledgementIsDeliveredTwiceAndImportsOnce(): void
    {
        $library = $this->library(LibraryType::Music);
        $path = $this->audioFile();
        $this->scanner(LibraryType::Music)->scan($library, publishDirectory: $this->capture($library));
        $this->expireAfterSend = true;
        $this->assertRelayFails();
        $first = $this->discovery(0, $library, $path);
        self::assertNull($this->observer->fetchOne("SELECT relayed_at FROM domain_event_outbox WHERE event_name = 'library.directory_indexed'"));
        $this->expireAfterSend = false;
        self::assertSame(1, ($this->relay)(new RelayOutboxCommand()));
        $duplicate = $this->discovery(1, $library, $path);
        self::assertEquals($first, $duplicate);
        $consumer = $this->kernel->getContainer()->get('test.service_container')->get(FilesDiscoveredHandler::class);
        $consumer($first);
        $consumer($duplicate);
        self::assertSame(1, $this->rowCount($this->observer, 'songs'));
        self::assertSame($path, $this->observer->fetchOne('SELECT path FROM songs'));
        self::assertSame([$path => true], $this->index->findAvailabilityByPaths($library->getId(), [$path]));
        self::assertNotNull($this->observer->fetchOne("SELECT relayed_at FROM domain_event_outbox WHERE event_name = 'library.directory_indexed'"));
    }

    private function library(LibraryType $type): Library
    {
        $library = Library::create('Directory publication', new LibrarySlug('directory-publication'), new LibraryPath($this->root), $type, FilesystemType::Local);
        (new LibraryRepository($this->manager, new NativeClock()))->save($library);
        return $library;
    }

    private function file(LibraryType $type, ?string $relative = null): string
    {
        $path = $this->root . '/' . ($relative ?? 'Album/01.' . ($type === LibraryType::Music ? 'flac' : 'mkv'));
        (new Filesystem())->mkdir(dirname($path));
        self::assertNotFalse(file_put_contents($path, 'directory publication fixture ' . basename($path)));
        return $path;
    }

    private function audioFile(): string
    {
        $path = $this->root . '/Album/01.flac';
        (new Filesystem())->mkdir(dirname($path));
        $fixture = new Process(['/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=0.1', '-c:a', 'flac', $path]);
        $fixture->mustRun();
        return $path;
    }

    private function scanner(LibraryType $type): MusicScanner|MovieScanner
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        $arguments = [new DirectoryScanner(), $this->index, new NullLogger(), $this->createStub(JobCancellationCheckpointInterface::class), $container->get(TransactionPortInterface::class)];
        return $type === LibraryType::Music ? new MusicScanner(...$arguments) : new MovieScanner(...$arguments);
    }

    /** @return Closure(string, array<DiscoveredFile>): void */
    private function capture(Library $library): Closure
    {
        return function (string $directory, array $files) use ($library): void {
            $this->events->dispatch(new LibraryDirectoryIndexed($library->getId()->toString(), $library->getType()->value, $directory, array_map(
                static fn (DiscoveredFile $file): array => ['absolute_path' => $file->absolutePath, 'relative_path' => $file->relativePath, 'extension' => $file->extension, 'size' => $file->size, 'modified_at' => $file->modifiedAt, 'hash' => $file->hash],
                $files,
            )));
        };
    }

    private function rowCount(Connection $connection, string $table): int
    {
        return (int) $connection->fetchOne('SELECT count(*) FROM ' . $connection->quoteIdentifier($table));
    }

    private function discovery(int $offset, Library $library, string $path): FilesDiscovered
    {
        $envelope = $this->transport->getSent()[$offset];
        self::assertSame(['async'], $envelope->last(TransportNamesStamp::class)?->getTransportNames());
        $message = $envelope->getMessage();
        self::assertInstanceOf(FilesDiscovered::class, $message);
        self::assertTrue($message->libraryId->equals($library->getId()));
        self::assertSame($library->getType()->value, $message->libraryType);
        self::assertSame(dirname($path), $message->directory);
        self::assertCount(1, $message->files);
        self::assertSame($path, $message->files[0]->absolutePath);
        return $message;
    }

    private function assertRelayFails(): void
    {
        try {
            ($this->relay)(new RelayOutboxCommand());
            self::fail('Relay failures must remain visible for retry.');
        } catch (OutboxRelayException $error) {
            self::assertStringContainsString('Outbox relay failed', $error->getMessage());
        }
    }
}
