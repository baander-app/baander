<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Interface\EventListener;

use App\Shared\Infrastructure\EventListener\ExceptionSubscriber;
use App\Transcode\Application\Exception\TranscodeStartupUnavailableException;
use App\Transcode\Interface\EventListener\TranscodeStartupUnavailableListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class TranscodeStartupUnavailableListenerTest extends TestCase
{
    #[DataProvider('startupFailures')]
    public function testStartupRefusalPrecedesGenericExceptionHandling(\Throwable $failure): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $dispatcher = new EventDispatcher();
        foreach ([new ExceptionSubscriber($logger, new IdentityTranslator()), new TranscodeStartupUnavailableListener()] as $listener) {
            $attribute = (new \ReflectionClass($listener))->getAttributes(AsEventListener::class)[0]->newInstance();
            $dispatcher->addListener(KernelEvents::EXCEPTION, $listener, $attribute->priority);
        }
        $event = $this->event($failure);

        $dispatcher->dispatch($event, KernelEvents::EXCEPTION);

        self::assertTrue($event->isPropagationStopped());
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('2', $response->headers->get('Retry-After'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
        self::assertSame(['error' => ['message' => 'Transcode startup is temporarily unavailable. Please retry.', 'code' => 503]],
            json_decode($response->getContent() ?: '', true, 32, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function startupFailures(): iterable
    {
        $failure = new TranscodeStartupUnavailableException();
        yield 'direct refusal' => [$failure];
        $wrapped = new HandlerFailedException(new Envelope(new \stdClass()), ['startup' => $failure]);
        yield 'named Messenger failure' => [$wrapped];
        yield 'nested Messenger failure' => [new HandlerFailedException(new Envelope(new \stdClass()), [$wrapped])];
    }

    #[DataProvider('unrelatedFailures')]
    public function testOtherFailuresAreUntouched(\Throwable $failure): void
    {
        $event = $this->event($failure);

        (new TranscodeStartupUnavailableListener())($event);

        self::assertSame($failure, $event->getThrowable());
        self::assertFalse($event->hasResponse());
        self::assertFalse($event->isPropagationStopped());
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function unrelatedFailures(): iterable
    {
        $unrelated = new \RuntimeException('Unrelated failure');
        $refusal = new TranscodeStartupUnavailableException();
        yield 'unrelated exception' => [$unrelated];
        yield 'unrelated Messenger failure' => [new HandlerFailedException(new Envelope(new \stdClass()), [$unrelated])];
        yield 'arbitrary previous refusal' => [new \RuntimeException('Other failure', 0, $refusal)];
        $mixed = new HandlerFailedException(new Envelope(new \stdClass()), [$refusal, $unrelated]);
        yield 'mixed handlers' => [$mixed];
        yield 'nested mixed handlers' => [new HandlerFailedException(new Envelope(new \stdClass()), [$mixed])];
        yield 'multiple refusals are not a single chain' => [new HandlerFailedException(new Envelope(new \stdClass()), [$refusal, $refusal])];
    }

    private function event(\Throwable $failure): ExceptionEvent
    {
        return new ExceptionEvent($this->createStub(HttpKernelInterface::class),
            Request::create('https://baander.app/api/transcode/sessions/', 'POST'), HttpKernelInterface::MAIN_REQUEST, $failure);
    }
}
