<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Notification\Application\DTO\SendPushCommand;
use App\Notification\Application\DTO\SendPushToSubscriptionCommand;
use App\Notification\Application\Handler\SendPushHandler;
use App\Notification\Application\Handler\SendPushToSubscriptionHandler;
use App\Notification\Application\Settings\NotificationSettingDefinitions;
use App\Notification\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Infrastructure\Doctrine\Entity\PushSubscriptionEntity;
use App\Notification\Infrastructure\Push\PushSubscriptionRepositoryInterface;
use App\Notification\Infrastructure\Push\WebPushDelivery;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\AddErrorDetailsStampListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Push delivery through a real worker and Redis transport: each subscription is its own message,
 * so a failing endpoint is retried alone and the others receive the notification once.
 */
final class PushRetryDeliveryTest extends TestCase
{
    /** @var list<Connection> */
    private array $connections = [];

    /** @return iterable<string, array{int|null}> */
    public static function recoverableFailures(): iterable
    {
        yield 'rate limit' => [429];
        yield 'server error' => [500];
        yield 'no answer' => [null];
    }

    #[DataProvider('recoverableFailures')]
    public function testAFailingEndpointIsRetriedAloneAndTheOthersReceiveTheNotificationOnce(?int $firstAnswer): void
    {
        $async = $this->transport();
        $failed = $this->transport();
        $owner = Uuid::generate();
        $subscriptions = new class ([
            'healthy' => new PushSubscriptionEntity($owner, 'https://push.baander.app/healthy', 'key', 'auth', 'aes128gcm'),
            'flaky' => new PushSubscriptionEntity($owner, 'https://push.baander.app/flaky', 'key', 'auth', 'aes128gcm'),
            'expired' => new PushSubscriptionEntity($owner, 'https://push.baander.app/expired', 'key', 'auth', 'aes128gcm'),
            'refused' => new PushSubscriptionEntity($owner, 'https://push.baander.app/refused', 'key', 'auth', 'aes128gcm'),
        ]) implements PushSubscriptionRepositoryInterface {
            /** @var list<string> */
            public array $removed = [];

            /** @param array<string, PushSubscriptionEntity> $subscriptions */
            public function __construct(private array $subscriptions)
            {
            }

            public function named(string $name): PushSubscriptionEntity
            {
                return $this->subscriptions[$name];
            }

            public function remove(PushSubscriptionEntity $subscription): void
            {
                $name = array_search($subscription, $this->subscriptions, true);
                if (is_string($name)) {
                    unset($this->subscriptions[$name]);
                    $this->removed[] = $name;
                }
            }

            public function find(Uuid $id): ?PushSubscriptionEntity
            {
                return array_find($this->subscriptions, static fn (PushSubscriptionEntity $subscription): bool => $subscription->getId()->equals($id));
            }

            public function findByUser(Uuid $userId): array
            {
                return array_values(array_filter(
                    $this->subscriptions,
                    static fn (PushSubscriptionEntity $subscription): bool => $subscription->getUserId()->equals($userId),
                ));
            }
        };
        $attempts = [];
        $webPush = $this->createStub(WebPush::class);
        $webPush->method('sendOneNotification')->willReturnCallback(
            static function (SubscriptionInterface $subscription) use (&$attempts, $firstAnswer): MessageSentReport {
                $name = basename($subscription->getEndpoint());
                $attempts[$name] = ($attempts[$name] ?? 0) + 1;
                $status = match ($name) {
                    'flaky' => $attempts[$name] === 1 ? $firstAnswer : 201,
                    'expired' => 410,
                    'refused' => 403,
                    default => 201,
                };

                return new MessageSentReport(
                    new Request('POST', $subscription->getEndpoint()),
                    $status === null ? null : new Response($status),
                    $status !== null && $status < 300,
                    'Push answer',
                );
            },
        );
        $bus = $this->bus($async, $subscriptions, $webPush);
        $command = new SendPushCommand($owner, NotificationCategory::Security, 'Title', 'Body', (new PublicId())->toString());

        $bus->dispatch($command);
        self::assertSame([], $attempts, 'Sending must enqueue before any push is sent.');
        $this->work($async, $failed, $bus);

        self::assertSame(['expired' => 1, 'flaky' => 2, 'healthy' => 1, 'refused' => 1], self::sorted($attempts));
        self::assertSame(['expired'], $subscriptions->removed);
        self::assertSame(0, $async->getMessageCount());
        $failures = iterator_to_array($failed->get());
        self::assertCount(1, $failures);
        $refused = array_values($failures)[0];
        self::assertInstanceOf(SendPushToSubscriptionCommand::class, $refused->getMessage());
        self::assertTrue($refused->getMessage()->subscriptionId->equals($subscriptions->named('refused')->getId()));
        self::assertSame($command->notificationPublicId, $refused->getMessage()->notificationPublicId);
        self::assertSame(UnrecoverableMessageHandlingException::class, $refused->last(ErrorDetailsStamp::class)?->getExceptionClass());
        $failed->ack($refused);
    }

    private function bus(RedisTransport $async, PushSubscriptionRepositoryInterface $subscriptions, WebPush $webPush): MessageBus
    {
        $preferences = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $preferences->method('isEnabled')->willReturn(true);
        $settings = $this->createStub(SystemSettingsPortInterface::class);
        $settings->method('get')->willReturnMap([[NotificationSettingDefinitions::PUSH_ENABLED, true]]);
        $delivery = new WebPushDelivery($subscriptions, $webPush);
        // The bus the fan-out handler dispatches to, set once the bus that runs it exists.
        $dispatcher = new class implements MessageBusInterface {
            public ?MessageBusInterface $bus = null;

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return ($this->bus ?? throw new \LogicException('The bus is not set.'))->dispatch($message, $stamps);
            }
        };
        $bus = new MessageBus([
            new SendMessageMiddleware(new SendersLocator(
                [SendPushCommand::class => ['async'], SendPushToSubscriptionCommand::class => ['async']],
                new ServiceLocator(['async' => static fn () => $async]),
            )),
            new HandleMessageMiddleware(new HandlersLocator([
                SendPushCommand::class => [new SendPushHandler($preferences, $delivery, $dispatcher, new NullLogger(), $settings)],
                SendPushToSubscriptionCommand::class => [new SendPushToSubscriptionHandler($delivery, new NullLogger(), 'baander.app', new JsonEncoder(), $settings)],
            ])),
        ]);
        $dispatcher->bus = $bus;

        return $bus;
    }

    private function work(RedisTransport $async, RedisTransport $failed, MessageBus $bus): void
    {
        $events = new EventDispatcher();
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
        (new Worker(['async' => $async], $bus, $events))->run(['sleep' => 1000, 'time_limit' => 5]);
    }

    /**
     * @param array<string, int> $attempts
     * @return array<string, int>
     */
    private static function sorted(array $attempts): array
    {
        ksort($attempts);

        return $attempts;
    }

    private function transport(): RedisTransport
    {
        $dsn = getenv('MESSENGER_TEST_REDIS_DSN');
        if (!$dsn) {
            self::markTestSkipped('Set MESSENGER_TEST_REDIS_DSN to an isolated Redis instance.');
        }
        $connection = Connection::fromDsn($dsn, [
            'stream' => 'push_retry_' . bin2hex(random_bytes(12)),
            'group' => 'test',
            'consumer' => 'test',
        ]);
        $this->connections[] = $connection;
        $transport = new RedisTransport($connection, new JsonTransportSerializer(MessageCodecFactory::create()));
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

