<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Messenger\SwooleTaskDispatcherInterface;
use App\Shared\Infrastructure\Messenger\SwooleTaskWithRedisFallbackSender;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

final class SwooleTaskWithRedisFallbackSenderTest extends TestCase
{
    private SwooleTaskDispatcherInterface&MockObject $dispatcher;
    private SenderInterface&MockObject $redisFallback;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(SwooleTaskDispatcherInterface::class);
        $this->redisFallback = $this->createMock(SenderInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function createSender(): SwooleTaskWithRedisFallbackSender
    {
        return new SwooleTaskWithRedisFallbackSender(
            $this->dispatcher,
            $this->redisFallback,
            $this->logger,
        );
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
        $this->redisFallback->method('send')->willReturn($envelope);
        $this->logger->expects($this->once())->method('warning');

        $result = $this->createSender()->send($envelope);

        $this->assertSame($envelope, $result);
    }

    public function testSendFallsBackToRedisWhenDispatchThrowsException(): void
    {
        $envelope = new Envelope(new \stdClass());
        $this->dispatcher->method('dispatchTask')->willThrowException(new \RuntimeException('Server shutting down'));
        $this->redisFallback->method('send')->willReturn($envelope);
        $this->logger->expects($this->exactly(2))->method('warning');

        $result = $this->createSender()->send($envelope);

        $this->assertSame($envelope, $result);
    }
}
