<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Infrastructure\Messenger\JobIdStamp;
use App\Shared\Infrastructure\Messenger\JobMonitoringMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;

final class JobMonitoringMiddlewareTest extends TestCase
{
    public function testDispatchStampsAJobIdBeforeTheTransportStoresTheMessage(): void
    {
        $transport = new InMemoryTransport();

        $this->bus($transport)->dispatch(new \stdClass());

        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        $stamp = $sent[0]->last(JobIdStamp::class);
        self::assertNotNull($stamp);
        self::assertSame($stamp->jobId->toString(), PublicId::fromString($stamp->jobId->toString())->toString());
    }

    public function testAnExistingJobIdIsKept(): void
    {
        $transport = new InMemoryTransport();
        $jobId = new PublicId();

        $this->bus($transport)->dispatch(new Envelope(new \stdClass(), [new JobIdStamp($jobId)]));

        self::assertSame([$jobId], array_map(
            static fn (JobIdStamp $stamp): PublicId => $stamp->jobId,
            $transport->getSent()[0]->all(JobIdStamp::class),
        ));
    }

    public function testAReceivedMessageGetsNoJobIdHere(): void
    {
        // The worker that received it records the job and assigns any missing ID.
        $seen = null;
        $bus = new MessageBus([new JobMonitoringMiddleware(), new class(function (Envelope $envelope) use (&$seen): void {
            $seen = $envelope;
        }) implements MiddlewareInterface {
            public function __construct(private readonly \Closure $capture)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ($this->capture)($envelope);

                return $envelope;
            }
        }]);

        $bus->dispatch(new Envelope(new \stdClass(), [new ReceivedStamp('async')]));

        self::assertInstanceOf(Envelope::class, $seen);
        self::assertNull($seen->last(JobIdStamp::class));
    }

    private function bus(InMemoryTransport $transport): MessageBus
    {
        $container = new Container();
        $container->set('async', $transport);

        return new MessageBus([
            new JobMonitoringMiddleware(),
            new SendMessageMiddleware(new SendersLocator([\stdClass::class => ['async']], $container)),
        ]);
    }
}
