<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler\Interface\EventListener;

use App\Scheduler\Domain\Exception\ScheduledJobConflict;
use App\Scheduler\Interface\EventListener\ScheduledJobConflictListener;
use App\Shared\Infrastructure\EventListener\ExceptionSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ScheduledJobConflictListenerTest extends TestCase
{
    public function testConflictReturnsApiErrorBeforeGenericExceptionListener(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $dispatcher = new EventDispatcher();
        // Read actual registration priorities, so changing the adapter's tag exercises ordering.
        $generic = new ExceptionSubscriber($logger);
        $specific = new ScheduledJobConflictListener();
        foreach ([$generic, $specific] as $listener) {
            $attribute = (new \ReflectionClass($listener))->getAttributes(AsEventListener::class)[0]->newInstance();
            self::assertSame(KernelEvents::EXCEPTION, $attribute->event);
            $dispatcher->addListener(KernelEvents::EXCEPTION, $listener, $attribute->priority);
        }
        $event = $this->event(new ScheduledJobConflict());
        $dispatcher->dispatch($event, KernelEvents::EXCEPTION);

        self::assertTrue($event->isPropagationStopped());
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame(['error' => ['message' => 'Scheduled job changed. Reload it and try again.', 'code' => 409]],
            json_decode($response->getContent() ?: '', true, 32, JSON_THROW_ON_ERROR));
    }

    public function testUnrelatedExceptionIsUntouched(): void
    {
        $error = new \RuntimeException('Unrelated scheduler failure');
        $event = $this->event($error);
        (new ScheduledJobConflictListener())($event);

        self::assertSame($error, $event->getThrowable());
        self::assertFalse($event->hasResponse());
        self::assertFalse($event->isPropagationStopped());
    }

    private function event(\Throwable $error): ExceptionEvent
    {
        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), Request::create('https://baander.app/api/admin/scheduler/jobs'), HttpKernelInterface::MAIN_REQUEST, $error);
    }
}
