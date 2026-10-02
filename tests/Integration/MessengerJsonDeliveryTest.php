<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use App\Shared\Infrastructure\Messenger\HttpServerTaskDispatcher;
use App\Shared\Infrastructure\Messenger\SwooleTaskDispatcherInterface;
use App\Shared\Infrastructure\Messenger\SwooleTaskWithRedisFallbackSender;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Messenger\SwooleServerTaskTransportFactory;
use SwooleBundle\SwooleBundle\Server\HttpServer;
use SwooleBundle\SwooleBundle\Server\HttpServerConfiguration;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
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
use Symfony\Component\Messenger\Transport\Serialization\SigningSerializer;
use Symfony\Component\Messenger\Worker;

final class MessengerJsonDeliveryTest extends TestCase
{
    /** @var list<Connection> */
    private array $connections = [];
    private int $completed = 0;
    private int $attempted = 0;

    public static function unavailableModes(): iterable
    {
        yield 'queue full' => [false];
        yield 'server unavailable' => [true];
        yield 'real unavailable server' => [null];
    }

    #[DataProvider('unavailableModes')]
    public function testRealRedisFallbackRetriesThenDeadLettersWithoutRepeatingCompletedHandler(?bool $throw): void
    {
        $async = $this->transport();
        $failed = $this->transport();
        $server = new HttpServer($this->createStub(HttpServerConfiguration::class));
        $serializer = new SigningSerializer(new JsonTransportSerializer(new JsonMessageCodec()), 'integration-test-key', [ExtractAlbumCoverCommand::class]);
        $dispatcher = $this->createStub(SwooleTaskDispatcherInterface::class);
        if ($throw === null) {
            $dispatcher = new HttpServerTaskDispatcher($server, $serializer);
        } elseif ($throw) {
            $dispatcher->method('dispatchTask')->willThrowException(new \RuntimeException('Swoole unavailable'));
        } else {
            $dispatcher->method('dispatchTask')->willReturn(false);
        }
        $sender = new SwooleTaskWithRedisFallbackSender($dispatcher, $async, new NullLogger());
        $factory = new SwooleServerTaskTransportFactory($server);
        $factory->setSender($sender);
        $transport = $factory->createTransport('swoole://task', [], $serializer);
        $bus = new MessageBus([
            new SendMessageMiddleware(new SendersLocator([ExtractAlbumCoverCommand::class => ['swoole_task']], new ServiceLocator(['swoole_task' => static fn () => $transport]))),
            new HandleMessageMiddleware(new HandlersLocator([ExtractAlbumCoverCommand::class => [$this->complete(...), function (ExtractAlbumCoverCommand $command): never {
                ++$this->attempted;
                throw new \RuntimeException('Poison message');
            }]])),
        ]);
        $command = new ExtractAlbumCoverCommand(Uuid::v4());
        $bus->dispatch($command);
        self::assertSame(0, $this->completed, 'Sending must not handle the command inline.');
        self::assertSame(1, $async->getMessageCount());

        $events = new EventDispatcher();
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
        $events->addSubscriber(new SendFailedMessageToFailureTransportListener(new ServiceLocator(['async' => static fn () => $failed])));
        // Bound the run even if a regression prevents the expected retry/dead-letter transition.
        $events->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event): void {
            if ($event->isWorkerIdle()) {
                $event->getWorker()->stop();
            }
        });
        (new Worker(['async' => $async], $bus, $events))->run(['sleep' => 1000, 'time_limit' => 3]);
        self::assertSame(1, $this->completed, 'Completed handlers must not repeat on retry.');
        self::assertSame(2, $this->attempted);
        self::assertSame([1], $retries);
        self::assertSame(0, $async->getMessageCount());
        self::assertSame(1, $failed->getMessageCount());
        $messages = iterator_to_array($failed->get());
        if ($messages === []) {
            $messages = iterator_to_array($failed->get());
        }
        self::assertCount(1, $messages);
        $envelope = array_values($messages)[0];
        self::assertEquals($command, $envelope->getMessage());
        // Messenger resets retries when moving to the failure transport for manual retry.
        self::assertSame(0, $envelope->last(RedeliveryStamp::class)?->getRetryCount());
        self::assertSame('async', $envelope->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName());
        self::assertSame('Poison message', $envelope->last(ErrorDetailsStamp::class)?->getExceptionMessage());
        $failed->ack($envelope);
        self::assertSame(0, $failed->getMessageCount());
    }

    public function complete(ExtractAlbumCoverCommand $command): void
    {
        ++$this->completed;
    }

    private function transport(): RedisTransport
    {
        $dsn = getenv('MESSENGER_TEST_REDIS_DSN');
        if (!$dsn) {
            self::markTestSkipped('Set MESSENGER_TEST_REDIS_DSN to an isolated Redis instance.');
        }
        $connection = Connection::fromDsn($dsn, ['stream' => 'test_' . bin2hex(random_bytes(12)), 'group' => 'test', 'consumer' => 'test']);
        $this->connections[] = $connection;
        $transport = new RedisTransport($connection, new SigningSerializer(new JsonTransportSerializer(new JsonMessageCodec()), 'integration-test-key', [ExtractAlbumCoverCommand::class]));
        $transport->setup();
        return $transport;
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->cleanup();
            $connection->close();
        }
    }
}
