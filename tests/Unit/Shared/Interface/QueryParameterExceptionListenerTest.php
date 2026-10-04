<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface;

use App\Shared\Interface\EventListener\QueryParameterExceptionListener;
use App\Shared\Interface\Exception\InvalidQueryParameter;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class QueryParameterExceptionListenerTest extends TestCase
{
    public function testInvalidQueryReturnsApiErrorWithParameterDetails(): void
    {
        $event = $this->event(new InvalidQueryParameter('limit', 'limit must be an integer.'));
        (new QueryParameterExceptionListener())($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame([
            'error' => [
                'message' => 'limit must be an integer.',
                'code' => 400,
                'details' => ['limit' => ['limit must be an integer.']],
            ],
        ], json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testUnrelatedExceptionsAreUntouched(): void
    {
        foreach ([new RuntimeException('Internal failure.'), new BadRequestHttpException('Unrelated request failure.')] as $exception) {
            $event = $this->event($exception);
            (new QueryParameterExceptionListener())($event);

            self::assertFalse($event->hasResponse());
            self::assertSame($exception, $event->getThrowable());
            self::assertFalse($event->isPropagationStopped());
        }
    }

    private function event(\Throwable $exception): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('https://baander.app/api/admin/users'),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
    }
}
