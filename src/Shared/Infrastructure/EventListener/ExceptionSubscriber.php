<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener;

use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Interface\DTO\ApiError;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Throwable;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 0)]
final class ExceptionSubscriber
{
    public function __construct(
        private readonly LoggerInterface $logger,
    )
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        // A handler's exception reaches the controller wrapped in HandlerFailedException.
        $exception = self::cause($event->getThrowable());
        if ($exception !== $event->getThrowable()) {
            $event->setThrowable($exception);
        }

        if ($this->respondWithOutcome($event, $exception)) {
            return;
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();

            // Don't log client errors (4xx)
            if ($status < 500) {
                return;
            }

        } else {
            $status = Response::HTTP_INTERNAL_SERVER_ERROR;

        }
        $this->logger->error('{exception_class}: {message}', [
            'exception_class' => $exception::class,
            'message'         => $exception->getMessage(),
            'exception'       => $exception,
        ]);

        $error = new ApiError(
            message: $this->getSafeMessage($exception, $status),
            code: $status,
        );

        $event->setResponse(new JsonResponse(
            $error->toArray(),
            $status,
        ));
    }

    /** The shared use case outcomes are client errors: 404, 409 or 422 with the message and details. */
    private function respondWithOutcome(ExceptionEvent $event, Throwable $exception): bool
    {
        [$status, $details] = match (true) {
            $exception instanceof NotFoundException => [Response::HTTP_NOT_FOUND, $exception->details],
            $exception instanceof ConflictException => [Response::HTTP_CONFLICT, $exception->details],
            $exception instanceof InvalidInputException => [Response::HTTP_UNPROCESSABLE_ENTITY, $exception->details],
            default => [null, []],
        };
        if ($status === null) {
            return false;
        }

        $error = new ApiError($exception->getMessage(), $status, $details);
        $event->setResponse(new JsonResponse($error->toArray(), $status));

        return true;
    }

    /** Unwraps HandlerFailedException, nested ones included, when it wraps a single exception. */
    private static function cause(Throwable $exception): Throwable
    {
        while ($exception instanceof HandlerFailedException && count($exception->getWrappedExceptions()) === 1) {
            [$exception] = array_values($exception->getWrappedExceptions());
        }

        return $exception;
    }

    private function getSafeMessage(Throwable $exception, int $status): string
    {
        if ($status < 500) {
            return $exception->getMessage();
        }

        return 'An unexpected error occurred.';
    }
}
