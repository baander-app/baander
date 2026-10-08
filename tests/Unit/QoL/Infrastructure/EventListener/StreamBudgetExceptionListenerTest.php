<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL\Infrastructure\EventListener;

use App\QoL\Domain\Exception\StreamBudgetExhausted;
use App\QoL\Infrastructure\EventListener\StreamBudgetExceptionListener;
use App\Shared\Infrastructure\EventListener\ExceptionSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\HttpKernel\KernelEvents;

final class StreamBudgetExceptionListenerTest extends TestCase
{
    public function testBudgetExhaustionPrecedesGenericErrorHandling(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $dispatcher = $this->dispatcher($logger);
        $event = $this->event(new StreamBudgetExhausted(
            activeStreams: 3,
            budgetUsed: 0.123456,
            requestedTier: 'high',
            message: 'Stream capacity reached.',
        ));

        $dispatcher->dispatch($event, KernelEvents::EXCEPTION);

        self::assertTrue($event->isPropagationStopped());
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame(
            [
                'error' => 'stream_budget_exhausted',
                'active_streams' => 3,
                'budget_used' => 0.1235,
                'requested_tier' => 'high',
                'message' => 'Stream capacity reached.',
            ],
            json_decode($response->getContent() ?: '', true, 32, JSON_THROW_ON_ERROR),
        );
    }

    public function testUnrelatedFailureReachesGenericHandler(): void
    {
        $failure = new \RuntimeException('Private error detail');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $dispatcher = $this->dispatcher($logger);
        $event = $this->event($failure);

        $dispatcher->dispatch($event, KernelEvents::EXCEPTION);

        self::assertSame($failure, $event->getThrowable());
        self::assertTrue($event->isPropagationStopped());
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame(
            ['error' => ['message' => 'An unexpected error occurred.', 'code' => 500]],
            json_decode($response->getContent() ?: '', true, 32, JSON_THROW_ON_ERROR),
        );
    }

    private function dispatcher(LoggerInterface $logger): EventDispatcher
    {
        $dispatcher = new EventDispatcher();
        foreach ([new ExceptionSubscriber($logger, new IdentityTranslator()), new StreamBudgetExceptionListener()] as $listener) {
            $attribute = (new \ReflectionClass($listener))
                ->getAttributes(AsEventListener::class)[0]
                ->newInstance();
            self::assertSame(KernelEvents::EXCEPTION, $attribute->event);
            $dispatcher->addListener(KernelEvents::EXCEPTION, $listener, $attribute->priority);
        }

        return $dispatcher;
    }

    private function event(\Throwable $failure): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('https://baander.app/api/stream/track'),
            HttpKernelInterface::MAIN_REQUEST,
            $failure,
        );
    }
}
