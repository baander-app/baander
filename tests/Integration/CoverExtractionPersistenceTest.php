<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Catalog\Application\Port\MetadataContentReaderPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Media\Application\Port\ImagePortInterface;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Domain\Model\StoredFile;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Metadata\Application\CommandHandler\ExtractAlbumCoverHandler;
use App\Metadata\Domain\Model\CoverArt;
use App\Metadata\Domain\Model\ExtractedMetadata;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use App\Shared\Infrastructure\Messenger\WorkerServicePoolResetSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy\ContextualProxy;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\ResetServicesListener;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\AddErrorDetailsStampListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Worker;

#[SkipDatabaseRollback]
final class CoverExtractionPersistenceTest extends TestCase
{
    /** @var list<Connection> */
    private array $connections = [];

    /** @var list<string> */
    private array $sourcePaths = [];

    public function testFlushFailureThenWorkerResetAllowsSameProcessRetry(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to the fully migrated disposable PostgreSQL database selected by DATABASE_URL.');
        }
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $params['serverVersion'] = '18';
        $observer = DriverManager::getConnection($params);
        // Installed Swoole resetter emits this diagnostic when discarding a closed manager.
        // Assert the specific output rather than weakening strict-output checks.
        $this->expectOutputString("[swoole] Resetting Doctrine EntityManager: Doctrine\\ORM\\EntityManager\n");
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        $em = $container->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(ContextualProxy::class, $em, 'Exercise the actual Swoole service proxy, not a standalone EntityManager.');
        self::assertSame(0, $em->getConnection()->getTransactionNestingLevel(), 'DAMA must not wrap this commit-visibility test.');
        self::assertSame($observer->fetchOne('SELECT current_database()'), $em->getConnection()->fetchOne('SELECT current_database()'));
        $library = new LibraryEntity('Cover recovery', 'cover-recovery-' . bin2hex(random_bytes(6)), '/tmp/cover-recovery', 'music', 'local');
        $em->persist($library);
        $em->flush();
        $album = Album::create($library->getId(), 'Recovery album', 'album');
        $albums = $container->get(AlbumPortInterface::class);
        $images = $container->get(ImagePortInterface::class);
        $albums->save($album);
        $albumId = $album->getId()->toString();
        self::assertSame(1, (int) $observer->fetchOne('SELECT COUNT(*) FROM albums WHERE id = ?', [$albumId]));
        $constraint = 'cover_retry_failure_' . bin2hex(random_bytes(8));
        $observer->executeStatement('ALTER TABLE images ADD CONSTRAINT ' . $constraint . " CHECK (album_id IS DISTINCT FROM '" . $albumId . "'::uuid)");
        try {
            $async = $this->transport();
            $failed = $this->transport();
            $sourcePath = tempnam(sys_get_temp_dir(), 'cover_retry_');
            self::assertNotFalse($sourcePath);
            $this->sourcePaths[] = $sourcePath;
            file_put_contents($sourcePath, 'local audio fixture');
            $song = Song::create($album->getId(), 'Song', $sourcePath, 19, 'audio/mpeg');
            $metadata = new ExtractedMetadata();
            $metadata->setPictures([new CoverArt(3, 'image/jpeg', 'front', 'image bytes', 64, 64)]);
            $reader = $this->createStub(MetadataContentReaderPortInterface::class);
            $reader->method('readMetadata')->willReturn($metadata);
            $songs = $this->createStub(SongPortInterface::class);
            $songs->method('findByAlbum')->willReturn([$song]);
            $storage = $this->createMock(StoragePortInterface::class);
            $attempts = 0;
            $destinations = [];
            $storage->method('storeFromBytes')->willReturnCallback(
                static function (string $bytes, string $destination) use (&$attempts, &$destinations): StoredFile {
                    ++$attempts;
                    $destinations[] = $destination;
                    return new StoredFile($destination, 'image/jpeg', strlen($bytes));
                },
            );
            $deleted = [];
            $storage->expects($this->once())->method('delete')->willReturnCallback(static function (string $path) use (&$deleted): void {
                $deleted[] = $path;
            });
            $handler = new ExtractAlbumCoverHandler($reader, $songs, $albums, $images, $storage, $em, new NullLogger());
            $bus = new MessageBus([
                new SendMessageMiddleware(new SendersLocator(
                    [ExtractAlbumCoverCommand::class => ['async']],
                    new ServiceLocator(['async' => static fn () => $async]),
                )),
                new HandleMessageMiddleware(new HandlersLocator([ExtractAlbumCoverCommand::class => [$handler]])),
            ]);
            $command = new ExtractAlbumCoverCommand($album->getId());
            $bus->dispatch($command);
            self::assertSame(0, $attempts, 'Dispatch must enqueue before cover extraction begins.');
            self::assertSame(1, $async->getMessageCount());

            $firstManager = $em->getServicePool()->get();
            $events = new EventDispatcher();
            $failures = 0;
            $events->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $event) use (&$failures, $firstManager, $observer, $albumId, $constraint): void {
                ++$failures;
                $causes = [];
                for ($cause = $event->getThrowable(); $cause !== null; $cause = $cause->getPrevious()) {
                    $causes[] = $cause::class . ': ' . $cause->getMessage();
                }
                self::assertSame(1, $failures, 'Only the injected first flush must fail: ' . implode(' -> ', $causes));
                self::assertStringContainsString($constraint, $event->getThrowable()->getMessage());
                self::assertFalse($firstManager->isOpen(), 'A real ORM flush failure closes the underlying manager.');
                self::assertSame(0, (int) $observer->fetchOne('SELECT COUNT(*) FROM images WHERE album_id = ?', [$albumId]));
                self::assertNull($observer->fetchOne('SELECT cover_image_id FROM albums WHERE id = ?', [$albumId]));
                $observer->executeStatement('ALTER TABLE images DROP CONSTRAINT ' . $constraint);
            }, -512);
            $events->addSubscriber($container->get(WorkerServicePoolResetSubscriber::class));
            $events->addSubscriber(new ResetServicesListener($kernel->getContainer()->get('services_resetter')));
            $identities = [];
            $events->addListener(WorkerMessageReceivedEvent::class, static function (WorkerMessageReceivedEvent $event) use (&$identities): void {
                $message = $event->getEnvelope()->getMessage();
                self::assertInstanceOf(ExtractAlbumCoverCommand::class, $message);
                $identities[] = $message->getAlbumId()->toString();
            });
            $retries = [];
            $events->addListener(WorkerMessageRetriedEvent::class, static function (WorkerMessageRetriedEvent $event) use (&$retries): void {
                $retries[] = $event->getEnvelope()->last(RedeliveryStamp::class)?->getRetryCount();
            });
            $events->addSubscriber(new AddErrorDetailsStampListener());
            $events->addSubscriber(new SendFailedMessageForRetryListener(
                new ServiceLocator(['async' => static fn () => $async]),
                new ServiceLocator(['async' => static fn () => new MultiplierRetryStrategy(1, 0)]),
                eventDispatcher: $events,
            ));
            $events->addSubscriber(new SendFailedMessageToFailureTransportListener(
                new ServiceLocator(['async' => static fn () => $failed]),
            ));
            $events->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event): void {
                if ($event->isWorkerIdle()) {
                    $event->getWorker()->stop();
                }
            });
            (new Worker(['async' => $async], $bus, $events))->run(['sleep' => 1000, 'time_limit' => 3]);

            self::assertSame(2, $attempts);
            self::assertSame([$command->getAlbumId()->toString(), $command->getAlbumId()->toString()], $identities);
            self::assertSame([1], $retries);
            self::assertSame(0, $async->getMessageCount());
            self::assertSame(0, $failed->getMessageCount());
            self::assertSame(1, $failures);
            self::assertCount(2, array_unique($destinations));
            self::assertSame([$destinations[0]], $deleted, 'Only the rolled-back attempt file is removed.');
            self::assertTrue($em->isOpen(), 'Existing handler/repository proxy references remain usable after reset.');
            self::assertSame(0, $em->getConnection()->getTransactionNestingLevel());
            $rows = $observer->fetchAllAssociative('SELECT i.id, i.path, a.cover_image_id FROM images i JOIN albums a ON a.id = i.album_id WHERE a.id = ?', [$albumId]);
            self::assertCount(1, $rows, 'Observer sees exactly one committed image after same-process retry.');
            self::assertSame($rows[0]['id'], $rows[0]['cover_image_id']);
            self::assertSame($destinations[1], $rows[0]['path']);
        } finally {
            $observer->executeStatement('ALTER TABLE images DROP CONSTRAINT IF EXISTS ' . $constraint);
            $observer->executeStatement('UPDATE albums SET cover_image_id = NULL WHERE id = ?', [$albumId]);
            $observer->executeStatement('DELETE FROM images WHERE album_id = ?', [$albumId]);
            $observer->executeStatement('DELETE FROM albums WHERE id = ?', [$albumId]);
            $observer->executeStatement('DELETE FROM libraries WHERE id = ?', [$library->getId()->toString()]);
            $kernel->shutdown();
            $observer->close();
        }
    }

    private function transport(): RedisTransport
    {
        $dsn = getenv('MESSENGER_TEST_REDIS_DSN');
        if (!$dsn) {
            self::markTestSkipped('Set MESSENGER_TEST_REDIS_DSN to an isolated Redis instance.');
        }
        $connection = Connection::fromDsn($dsn, [
            'stream' => 'cover_extraction_retry_' . bin2hex(random_bytes(12)),
            'group' => 'test',
            'consumer' => 'test',
        ]);
        $this->connections[] = $connection;
        $transport = new RedisTransport($connection, new JsonTransportSerializer(new JsonMessageCodec()));
        $transport->setup();
        return $transport;
    }

    protected function tearDown(): void
    {
        foreach ($this->sourcePaths as $path) {
            unlink($path);
        }
        foreach ($this->connections as $connection) {
            $connection->cleanup();
            $connection->close();
        }
    }
}
