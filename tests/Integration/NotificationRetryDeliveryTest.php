<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use App\Notification\Application\DTO\SendEmailCommand;
use App\Notification\Application\Handler\SendEmailHandler;
use App\Notification\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use App\UserPreference\Application\Port\UserSettingsContractInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
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
use Symfony\Component\Mime\Email;
use Symfony\Component\Translation\IdentityTranslator;
use Twig\Environment;

final class NotificationRetryDeliveryTest extends TestCase
{
    /** @var list<Connection> */
    private array $connections = [];

    /** @return iterable<string, array{bool}> */
    public static function deliveryOutcomes(): iterable
    {
        yield 'transport remains unavailable' => [false];
        yield 'transport recovers on retry' => [true];
    }

    #[DataProvider('deliveryOutcomes')]
    public function testEmailTransportFailureRetriesAndPreservesDeliveryIdentity(bool $recover): void
    {
        $async = $this->transport();
        $failed = $this->transport();
        $preferences = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $preferences->method('isEnabled')->willReturn(true);
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<p>Notification</p>');
        $mailer = $this->createMock(MailerInterface::class);
        $attempts = 0;
        $mailer->expects($this->exactly(2))->method('send')
            ->with($this->callback(static fn (Email $email): bool => $email->getTo()[0]->getAddress() === 'user@baander.app'))
            ->willReturnCallback(static function () use (&$attempts, $recover): void {
                ++$attempts;
                if (!$recover || $attempts === 1) {
                    throw new TransportException('SMTP unavailable');
                }
            });
        $userSettings = $this->createStub(UserSettingsContractInterface::class);
        $userSettings->method('resolveLanguage')->willReturn('en');
        $handler = new SendEmailHandler($preferences, $userSettings, $mailer, $twig, new IdentityTranslator(), new NullLogger(), 'baander.app', 'Baander');
        $bus = new MessageBus([
            new SendMessageMiddleware(new SendersLocator(
                [SendEmailCommand::class => ['async']],
                new ServiceLocator(['async' => static fn () => $async]),
            )),
            new HandleMessageMiddleware(new HandlersLocator([SendEmailCommand::class => [$handler]])),
        ]);
        $command = new SendEmailCommand(
            Uuid::v4(),
            'user@baander.app',
            NotificationCategory::Security,
            'user.password_changed.title',
            [],
            'user.password_changed.body',
            [],
            new \DateTimeImmutable('2026-10-02T12:00:00.123+00:00'),
            (new PublicId())->toString(),
        );
        $bus->dispatch($command);
        self::assertSame(0, $attempts, 'Sending must enqueue before email delivery begins.');
        self::assertSame(1, $async->getMessageCount());

        $events = new EventDispatcher();
        $identities = [];
        $events->addListener(WorkerMessageReceivedEvent::class, static function (WorkerMessageReceivedEvent $event) use (&$identities): void {
            $message = $event->getEnvelope()->getMessage();
            self::assertInstanceOf(SendEmailCommand::class, $message);
            $identities[] = $message->notificationPublicId;
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
        self::assertSame([$command->notificationPublicId, $command->notificationPublicId], $identities);
        self::assertSame([1], $retries);
        self::assertSame(0, $async->getMessageCount());
        self::assertSame($recover ? 0 : 1, $failed->getMessageCount());
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
        self::assertSame($command->notificationPublicId, $envelope->getMessage()->notificationPublicId);
        self::assertSame('async', $envelope->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName());
        self::assertSame(TransportException::class, $envelope->last(ErrorDetailsStamp::class)?->getExceptionClass());
        self::assertSame('SMTP unavailable', $envelope->last(ErrorDetailsStamp::class)->getExceptionMessage());
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
            'stream' => 'notification_retry_' . bin2hex(random_bytes(12)),
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
