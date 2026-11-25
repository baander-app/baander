<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Cache\AuthenticatedMarshaller;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use App\Shared\Infrastructure\Messenger\UndecodableDeliverySubscriber;
use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\AddErrorDetailsStampListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\DecodeFailedMessageMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\SigningSerializer;
use Symfony\Component\Messenger\Worker;

/**
 * What a process that can write to Redis, but does not hold APP_SECRET, can do to the
 * Messenger streams and the cache pools.
 */
final class SignedRedisPayloadTest extends TestCase
{
    private const string SECRET = 'integration-signing-secret-of-32-bytes';

    /** @var list<Connection> */
    private array $connections = [];

    /** @var list<string> */
    private array $cacheKeys = [];

    private int $handled = 0;

    public function testAnEnvelopeWrittenWithoutASignatureIsDroppedAndItsHandlerDoesNotRun(): void
    {
        [$transport, $connection] = $this->transport();
        $connection->add(...$this->unsigned(new ExtractAlbumCoverCommand(Uuid::v4())));

        [$failed, $logs] = $this->consume($transport);

        self::assertSame(0, $this->handled);
        self::assertSame([], $failed->getSent());
        self::assertSame(0, $transport->getMessageCount());
        self::assertCount(1, $logs);
        self::assertStringContainsString('requires a signature but none was found', $logs[0][2]['error']);
    }

    public function testAnEnvelopeWhoseBodyChangedAfterSigningIsDropped(): void
    {
        [$transport, $connection] = $this->transport();
        $encoded = $this->signing()->encode(new Envelope(new ExtractAlbumCoverCommand(Uuid::v4())));
        $altered = preg_replace('/"album_id":"[^"]+"/', '"album_id":"' . Uuid::v4()->toString() . '"', $encoded['body'], 1) ?? '';
        self::assertNotSame($encoded['body'], $altered);
        $connection->add($altered, $encoded['headers']);

        [$failed, $logs] = $this->consume($transport);

        self::assertSame(0, $this->handled);
        self::assertSame([], $failed->getSent());
        self::assertSame(0, $transport->getMessageCount());
        self::assertCount(1, $logs);
        self::assertStringContainsString('Invalid signature', $logs[0][2]['error']);
    }

    public function testASignedEnvelopeIsHandled(): void
    {
        [$transport] = $this->transport();
        $transport->send(new Envelope(new ExtractAlbumCoverCommand(Uuid::v4())));

        [$failed, $logs] = $this->consume($transport);

        self::assertSame(1, $this->handled);
        self::assertSame([], $failed->getSent());
        self::assertSame([], $logs);
    }

    public function testAFloodOfForgedEnvelopesAddsNothingToTheFailureTransport(): void
    {
        [$transport, $connection] = $this->transport();
        for ($index = 0; $index < 25; ++$index) {
            $connection->add(...$this->unsigned(new ExtractAlbumCoverCommand(Uuid::v4())));
        }
        // A body the codec refuses fails before the signature is judged.
        $connection->add('{"format":"php","body":"O:8:\"stdClass\":0:{}"}', []);

        [$failed, $logs] = $this->consume($transport);

        self::assertSame(0, $this->handled);
        self::assertSame([], $failed->getSent());
        self::assertSame(0, $transport->getMessageCount());
        self::assertCount(26, $logs, 'Each dropped envelope writes one log line.');
    }

    public function testACacheValueWrittenWithoutATagIsAMissAndIsNeverInstantiated(): void
    {
        $redis = $this->redis();
        $namespace = 'signed_payload_' . bin2hex(random_bytes(6));
        $pool = new RedisAdapter($redis, $namespace, 0, new AuthenticatedMarshaller(new DefaultMarshaller(), self::SECRET));
        $pool->save($pool->getItem('kept')->set(['keys' => ['kid-1']]));
        self::assertSame(['keys' => ['kid-1']], $pool->getItem('kept')->get());

        $this->cacheKeys[] = $key = $namespace . ':forged';
        $redis->set($key, serialize(new UnserializeProbe()));
        UnserializeProbe::$built = 0;

        $item = $pool->getItem('forged');

        self::assertFalse($item->isHit());
        self::assertSame(0, UnserializeProbe::$built);
    }

    public function testACacheValueTaggedUnderAnotherSecretIsAMiss(): void
    {
        $redis = $this->redis();
        $namespace = 'signed_payload_' . bin2hex(random_bytes(6));
        $this->cacheKeys[] = $namespace . ':entry';
        $other = new RedisAdapter($redis, $namespace, 0, new AuthenticatedMarshaller(new DefaultMarshaller(), 'another-secret-of-at-least-32-bytes'));
        $other->save($other->getItem('entry')->set(new UnserializeProbe()));
        UnserializeProbe::$built = 0;

        $pool = new RedisAdapter($redis, $namespace, 0, new AuthenticatedMarshaller(new DefaultMarshaller(), self::SECRET));

        self::assertFalse($pool->getItem('entry')->isHit());
        self::assertSame(0, UnserializeProbe::$built);
    }

    public function complete(ExtractAlbumCoverCommand $command): void
    {
        ++$this->handled;
    }

    /** @return array{InMemoryTransport, list<array{mixed, string, array<string, mixed>}>} */
    private function consume(RedisTransport $transport): array
    {
        $failed = new InMemoryTransport();
        $logger = new BufferingLogger();
        $events = new EventDispatcher();
        $events->addSubscriber(new AddErrorDetailsStampListener());
        $events->addSubscriber(new SendFailedMessageForRetryListener(
            new ServiceLocator(['async' => static fn(): RedisTransport => $transport]),
            new ServiceLocator(['async' => static fn(): MultiplierRetryStrategy => new MultiplierRetryStrategy(3, 0)]),
            $logger,
            $events,
        ));
        $events->addSubscriber(new UndecodableDeliverySubscriber());
        $events->addSubscriber(new SendFailedMessageToFailureTransportListener(new ServiceLocator(['async' => static fn(): InMemoryTransport => $failed])));
        $events->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event): void {
            if ($event->isWorkerIdle()) {
                $event->getWorker()->stop();
            }
        });
        $bus = new MessageBus([
            new DecodeFailedMessageMiddleware(new ServiceLocator(['async' => fn(): SigningSerializer => $this->signing()])),
            new HandleMessageMiddleware(new HandlersLocator([ExtractAlbumCoverCommand::class => [$this->complete(...)]])),
        ]);
        (new Worker(['async' => $transport], $bus, $events))->run(['sleep' => 100, 'time_limit' => 5]);

        return [$failed, $logger->cleanLogs()];
    }

    /** @return array{string, array<string, mixed>} The body and headers the JSON serializer writes, unsigned. */
    private function unsigned(object $message): array
    {
        $encoded = (new JsonTransportSerializer(MessageCodecFactory::create()))->encode(new Envelope($message));

        return [$encoded['body'], $encoded['headers'] ?? []];
    }

    private function signing(): SigningSerializer
    {
        return MessageCodecFactory::signingSerializer(secret: self::SECRET);
    }

    /** @return array{RedisTransport, Connection} */
    private function transport(): array
    {
        $dsn = getenv('MESSENGER_TEST_REDIS_DSN');
        if (!$dsn) {
            self::markTestSkipped('Set MESSENGER_TEST_REDIS_DSN to an isolated Redis instance.');
        }
        $connection = Connection::fromDsn($dsn, ['stream' => 'test_' . bin2hex(random_bytes(12)), 'group' => 'test', 'consumer' => 'test', 'delete_after_ack' => true]);
        $this->connections[] = $connection;
        $transport = new RedisTransport($connection, $this->signing());
        $transport->setup();

        return [$transport, $connection];
    }

    private function redis(): \Redis
    {
        $dsn = getenv('MESSENGER_TEST_REDIS_DSN');
        if (!$dsn) {
            self::markTestSkipped('Set MESSENGER_TEST_REDIS_DSN to an isolated Redis instance.');
        }
        $redis = RedisAdapter::createConnection($dsn);
        self::assertInstanceOf(\Redis::class, $redis);

        return $redis;
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->cleanup();
            $connection->close();
        }
        if ($this->cacheKeys !== []) {
            $this->redis()->del($this->cacheKeys);
        }
    }
}

/** Counts every object PHP builds from a serialized string. */
final class UnserializeProbe
{
    public static int $built = 0;

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        ++self::$built;
    }
}
