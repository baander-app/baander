<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Shared\Application\Port\ServerDrainStateInterface;
use App\Shared\Infrastructure\Messenger\SwooleTaskDispatcherInterface;
use App\Shared\Infrastructure\Messenger\SwooleTaskWithRedisFallbackSender;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Messenger\SwooleServerTaskSender;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

final class SwooleTaskWithRedisFallbackSenderTest extends TestCase
{
    private SwooleTaskDispatcherInterface&Stub $dispatcher;
    private SenderInterface&MockObject $redisFallback;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createStub(SwooleTaskDispatcherInterface::class);
        $this->redisFallback = $this->createMock(SenderInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function createSender(bool $draining = false): SwooleTaskWithRedisFallbackSender
    {
        $drain = $this->createStub(ServerDrainStateInterface::class);
        $drain->method('isDraining')->willReturn($draining);

        return new SwooleTaskWithRedisFallbackSender(
            $this->dispatcher,
            $this->redisFallback,
            $this->logger,
            $drain,
        );
    }

    public function testWhileTheServerDrainsEveryMessageGoesToRedisWithoutTryingTheTaskQueue(): void
    {
        $dispatcher = $this->createMock(SwooleTaskDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatchTask');
        $this->dispatcher = $dispatcher;
        $envelope = (new Envelope(new \stdClass()))->with(new SentStamp(SwooleServerTaskSender::class, 'swoole_task'));
        $this->redisFallback->expects($this->once())->method('send')
            ->with(self::callback(static fn (Envelope $sent): bool => $sent->getMessage() === $envelope->getMessage()
                && $sent->last(ReceivedStamp::class)?->getTransportName() === 'swoole_task'))
            ->willReturnArgument(0);
        $this->logger->expects($this->never())->method('warning');

        $this->createSender(draining: true)->send($envelope);
    }

    public function testOutsideADrainTheTaskQueueIsTriedFirst(): void
    {
        $dispatcher = $this->createMock(SwooleTaskDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatchTask')->willReturn(true);
        $this->dispatcher = $dispatcher;
        $this->redisFallback->expects($this->never())->method('send');
        $this->logger->expects($this->never())->method('warning');

        $this->createSender(draining: false)->send(new Envelope(new \stdClass()));
    }

    public function testSendDispatchesToSwooleWhenSuccessful(): void
    {
        $envelope = new Envelope(new \stdClass());
        $this->dispatcher->method('dispatchTask')->willReturn(true);
        $this->redisFallback->expects($this->never())->method('send');
        $this->logger->expects($this->never())->method('warning');

        $result = $this->createSender()->send($envelope);

        $this->assertSame($envelope, $result);
    }

    public function testSendFallsBackToRedisWhenTaskQueueIsFull(): void
    {
        $envelope = new Envelope(new \stdClass());
        $this->dispatcher->method('dispatchTask')->willReturn(false);
        $this->redisFallback->expects($this->once())->method('send')->willReturn($envelope);
        $this->logger->expects($this->once())->method('warning');

        $result = $this->createSender()->send($envelope);

        $this->assertSame($envelope, $result);
    }

    public function testSendFallsBackToRedisWhenDispatchThrowsException(): void
    {
        $envelope = new Envelope(new \stdClass());
        $this->dispatcher->method('dispatchTask')->willThrowException(new \RuntimeException('Server shutting down'));
        $this->redisFallback->expects($this->once())->method('send')->willReturn($envelope);
        $this->logger->expects($this->exactly(2))->method('warning');

        $result = $this->createSender()->send($envelope);

        $this->assertSame($envelope, $result);
    }
}
