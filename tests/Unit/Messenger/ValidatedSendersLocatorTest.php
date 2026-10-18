<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger;

use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messaging\AsyncWorkloadCatalog;
use App\Shared\Infrastructure\Messenger\ValidatedSendersLocator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;

final class ValidatedSendersLocatorTest extends TestCase
{
    private InMemoryTransport $async;
    private InMemoryTransport $scheduler;
    private InMemoryTransport $failed;
    private Container $senders;

    protected function setUp(): void
    {
        $this->async = new InMemoryTransport();
        $this->scheduler = new InMemoryTransport();
        $this->failed = new InMemoryTransport();
        $this->senders = new Container();
        $this->senders->set('async', $this->async);
        $this->senders->set('scheduler', $this->scheduler);
        $this->senders->set('failed', $this->failed);
    }

    public function testUncatalogedMessageCannotSelectAnAsynchronousSender(): void
    {
        $this->assertRejectedBeforeSend(
            $this->locator(),
            new Envelope(new \stdClass(), [new TransportNamesStamp(['async'])]),
        );
    }

    public function testDefaultWorkloadsReachTheirDeclaredQueues(): void
    {
        $bus = new MessageBus([new SendMessageMiddleware($this->locator())]);
        $async = new QueuedWork();
        $scheduled = new ScheduledWork();

        $bus->dispatch($async);
        $bus->dispatch($scheduled);

        self::assertSame($async, $this->async->getSent()[0]->getMessage());
        self::assertSame($scheduled, $this->scheduler->getSent()[0]->getMessage());
        self::assertSame([], $this->failed->getSent());
    }

    public function testLyricsKeepTheirSynchronousResultUnlessExplicitlyQueued(): void
    {
        $handlerCalls = 0;
        $message = new FetchLyricsCommand(Uuid::generate());
        $bus = new MessageBus([
            new SendMessageMiddleware($this->locator()),
            new HandleMessageMiddleware(new HandlersLocator([
                FetchLyricsCommand::class => [static function () use (&$handlerCalls): string {
                    ++$handlerCalls;

                    return 'lyrics fetched';
                }],
            ])),
        ]);

        $synchronous = $bus->dispatch($message);
        self::assertSame('lyrics fetched', $synchronous->last(HandledStamp::class)?->getResult());
        self::assertSame([], $this->async->getSent());

        $queued = $bus->dispatch($message, [new TransportNamesStamp(['async'])]);
        self::assertNull($queued->last(HandledStamp::class));
        self::assertSame($message, $this->async->getSent()[0]->getMessage());
        self::assertSame(1, $handlerCalls);
    }

    public function testUncatalogedSynchronousMessageStillRunsItsHandler(): void
    {
        $bus = new MessageBus([
            new SendMessageMiddleware($this->locator()),
            new HandleMessageMiddleware(new HandlersLocator([
                \stdClass::class => [static fn (): string => 'handled'],
            ])),
        ]);

        self::assertSame('handled', $bus->dispatch(new \stdClass())->last(HandledStamp::class)?->getResult());
        self::assertSame([], $this->async->getSent());
    }

    /** @return iterable<string, array{list<string>}> */
    public static function forbiddenOverrides(): iterable
    {
        yield 'wrong queue' => [['scheduler']];
        yield 'failure queue' => [['failed']];
        yield 'fanout after valid queue' => [['async', 'scheduler']];
        yield 'fanout before valid queue' => [['scheduler', 'async']];
        yield 'missing required sender' => [[]];
    }

    /** @param list<string> $transports */
    #[DataProvider('forbiddenOverrides')]
    public function testProducerOverrideMustResolveToExactlyThePermittedQueue(array $transports): void
    {
        $this->assertRejectedBeforeSend(
            $this->locator(),
            new Envelope(new QueuedWork(), [new TransportNamesStamp($transports)]),
        );
    }

    public function testWildcardRoutesAreValidatedAfterSymfonyResolvesThem(): void
    {
        $this->assertRejectedBeforeSend(
            $this->locator(['*' => ['async']]),
            new Envelope(new \stdClass()),
        );
    }

    public function testUnknownAliasIsResolvedBeforeAnySenderIsExposed(): void
    {
        $envelope = new Envelope(new QueuedWork(), [new TransportNamesStamp(['async', 'missing'])]);
        $this->expectException(\Symfony\Component\Messenger\Exception\RuntimeException::class);

        try {
            foreach ($this->locator()->getSenders($envelope) as $sender) {
                $sender->send($envelope);
            }
        } finally {
            self::assertSame([], $this->async->getSent());
        }
    }

    public function testInlineReceivedExecutionRetainsTransportRestrictedHandlers(): void
    {
        $bus = new MessageBus([
            new SendMessageMiddleware($this->locator()),
            new HandleMessageMiddleware(new HandlersLocator([
                QueuedWork::class => [new HandlerDescriptor(
                    static fn (): string => 'inline result',
                    ['from_transport' => 'async'],
                )],
            ])),
        ]);
        $envelope = new Envelope(new QueuedWork(), [
            new ReceivedStamp('async'),
            new TransportNamesStamp(['failed']),
        ]);

        self::assertSame('inline result', $bus->dispatch($envelope)->last(HandledStamp::class)?->getResult());
        self::assertSame([], $this->async->getSent());
        self::assertSame([], $this->failed->getSent());
        self::assertSame(['failed'], array_keys(iterator_to_array($this->locator()->getSenders($envelope))));
    }

    public function testFrameworkFailureAndRetrySendersKeepTheirDirectTransportPath(): void
    {
        $envelope = new Envelope(new QueuedWork(), [new ReceivedStamp('async')]);
        $failureSenders = new Container();
        $failureSenders->set('async', $this->failed);
        $failureListener = new SendFailedMessageToFailureTransportListener($failureSenders);
        $failureListener->onMessageFailed(new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException('failed')));
        self::assertSame('async', $this->failed->getSent()[0]->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName());

        $strategies = new Container();
        $strategies->set('async', new MultiplierRetryStrategy(3, 0));
        $retryListener = new SendFailedMessageForRetryListener($this->senders, $strategies);
        $event = new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException('retry'));
        $retryListener->onMessageFailed($event);

        self::assertTrue($event->willRetry());
        self::assertSame(1, $this->async->getSent()[0]->last(RedeliveryStamp::class)?->getRetryCount());
    }

    /** @param array<string, list<string>>|null $routes */
    private function locator(?array $routes = null): ValidatedSendersLocator
    {
        $policy = ['max_retries' => 3, 'delay' => 1000, 'multiplier' => 2, 'max_delay' => 60000];
        $catalog = new AsyncWorkloadCatalog([
            QueuedWork::class => ['transport' => 'async', 'resource_class' => 'io', 'default' => true],
            ScheduledWork::class => ['transport' => 'scheduler', 'resource_class' => 'coordination', 'default' => true],
            FetchLyricsCommand::class => ['transport' => 'async', 'resource_class' => 'io', 'default' => false],
        ], ['async' => $policy, 'scheduler' => $policy]);

        return new ValidatedSendersLocator(new SendersLocator($routes ?? [
            QueuedWork::class => ['async'],
            ScheduledWork::class => ['scheduler'],
        ], $this->senders), $catalog);
    }

    private function assertRejectedBeforeSend(ValidatedSendersLocator $locator, Envelope $envelope): void
    {
        try {
            foreach ($locator->getSenders($envelope) as $sender) {
                $sender->send($envelope);
            }
            self::fail('The sender policy accepted an unsupported route.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString($envelope->getMessage()::class, $exception->getMessage());
        }

        self::assertSame([], $this->async->getSent());
        self::assertSame([], $this->scheduler->getSent());
        self::assertSame([], $this->failed->getSent());
    }
}

final class QueuedWork
{
}

final class ScheduledWork
{
}
