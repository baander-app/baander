<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\MetadataContentReaderPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Media\Application\Port\ImagePortInterface;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Domain\Model\Image;
use App\Media\Domain\Model\StoredFile;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Metadata\Application\CommandHandler\ExtractAlbumCoverHandler;
use App\Metadata\Domain\Model\CoverArt;
use App\Metadata\Domain\Model\ExtractedMetadata;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
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
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Worker;

final class CoverExtractionRetryTest extends TestCase
{
    /** @var list<Connection> */
    private array $connections = [];

    /** @var list<string> */
    private array $sourcePaths = [];

    /** @return iterable<string, array{bool}> */
    public static function deliveryOutcomes(): iterable
    {
        yield 'transport remains unavailable' => [false];
        yield 'transport recovers on retry' => [true];
    }

    #[DataProvider('deliveryOutcomes')]
    public function testStorageFailureRetriesAndPreservesAlbumIdentity(bool $recover): void
    {
        $async = $this->transport();
        $failed = $this->transport();
        $album = Album::create(Uuid::v4(), 'Retry album', 'album');
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
        $albums = $this->createMock(AlbumPortInterface::class);
        $albums->method('findByUuid')->willReturn($album);
        $albums->expects($recover ? $this->once() : $this->never())->method('save')->with($album);
        $images = $this->createMock(ImagePortInterface::class);
        $images->expects($recover ? $this->once() : $this->never())->method('save')
            ->with($this->callback(static fn (Image $image): bool => $image->getAlbumId()?->equals($album->getId()) === true));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($recover ? $this->once() : $this->never())->method('beginTransaction');
        $em->expects($recover ? $this->once() : $this->never())->method('commit');
        $em->expects($this->never())->method('rollback');
        $storage = $this->createMock(StoragePortInterface::class);
        $attempts = 0;
        $destinations = [];
        $storage->method('storeFromBytes')->willReturnCallback(
            static function (string $bytes, string $destination) use (&$attempts, &$destinations, $recover): StoredFile {
                self::assertSame('image bytes', $bytes);
                $destinations[] = $destination;
                ++$attempts;
                if (!$recover || $attempts === 1) {
                    throw new \RuntimeException('Cover storage unavailable');
                }
                return new StoredFile($destination, 'image/jpeg', strlen($bytes));
            },
        );
        $storage->expects($this->never())->method('delete');
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

        $events = new EventDispatcher();
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
        self::assertSame($recover ? 0 : 1, $failed->getMessageCount());
        foreach ($destinations as $destination) {
            self::assertMatchesRegularExpression('#^images/album/' . preg_quote($album->getId()->toString(), '#') . '/[0-9a-f-]+\\.jpg$#', $destination);
        }
        self::assertCount(2, array_unique($destinations), 'Retries need separate storage destinations.');
        self::assertSame($recover, $album->getCoverImageId() !== null);
        if ($recover) {
            return;
        }

        $messages = iterator_to_array($failed->get());
        if ($messages === []) {
            $messages = iterator_to_array($failed->get());
        }
        self::assertCount(1, $messages);
        $envelope = array_values($messages)[0];
        self::assertEquals($command, $envelope->getMessage());
        self::assertSame($command->getAlbumId()->toString(), $envelope->getMessage()->getAlbumId()->toString());
        self::assertSame('async', $envelope->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName());
        self::assertSame(\RuntimeException::class, $envelope->last(ErrorDetailsStamp::class)?->getExceptionClass());
        self::assertSame('Cover storage unavailable', $envelope->last(ErrorDetailsStamp::class)->getExceptionMessage());
        $failed->ack($envelope);
        self::assertSame(0, $failed->getMessageCount());
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
