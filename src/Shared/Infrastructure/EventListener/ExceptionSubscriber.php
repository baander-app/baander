<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener;

use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\HandlerFailure;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use Baander\RedisRateLimiter\RateLimiterUnavailableException;
use App\Shared\Application\Exception\RetryableFault;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Http\RequestLocale;
use App\Shared\Interface\DTO\ApiError;
use Doctrine\DBAL\Exception\DriverException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 0)]
final class ExceptionSubscriber
{
    /** PostgreSQL's lock_not_available: a lock wait passed the transaction's lock_timeout. */
    private const string LOCK_NOT_AVAILABLE = '55P03';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    )
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        // A handler's exception reaches the controller wrapped in HandlerFailedException.
        $exception = HandlerFailure::cause($event->getThrowable());
        if ($exception !== $event->getThrowable()) {
            $event->setThrowable($exception);
        }

        if ($this->respondWithOutcome($event, $exception) || $this->respondWithRetryAfter($event, $exception) || $this->respondToRefusal($event, $exception)) {
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

        // A 503 raised with a retry delay keeps its Retry-After and cache headers, so an
        // endpoint can answer a server fault by throwing; other 5xx carry none.
        $headers = $exception instanceof HttpExceptionInterface && $status === Response::HTTP_SERVICE_UNAVAILABLE
            ? $exception->getHeaders()
            : [];

        $event->setResponse(new JsonResponse(
            $error->toArray(),
            $status,
            $headers,
        ));
    }

    /**
     * The shared use case outcomes: 404, 409 or 422 for client errors, and 503 when an external
     * service did not answer, each with the message and details.
     * An outcome that is also TranslatableInterface is reported in the request's language
     * (RequestLocale); getMessage() stays English for the console and logs.
     */
    private function respondWithOutcome(ExceptionEvent $event, Throwable $exception): bool
    {
        [$status, $details] = match (true) {
            $exception instanceof NotFoundException => [Response::HTTP_NOT_FOUND, $exception->details],
            $exception instanceof ConflictException => [Response::HTTP_CONFLICT, $exception->details],
            $exception instanceof InvalidInputException => [Response::HTTP_UNPROCESSABLE_ENTITY, $exception->details],
            $exception instanceof RateLimiterUnavailableException => [Response::HTTP_SERVICE_UNAVAILABLE, []],
            $exception instanceof ServiceUnavailableException => [Response::HTTP_SERVICE_UNAVAILABLE, $exception->details],
            default => [null, []],
        };
        if ($status === null) {
            return false;
        }

        $message = $exception instanceof TranslatableInterface
            ? $exception->trans($this->translator, $this->locale($event))
            : $exception->getMessage();
        $error = new ApiError($message, $status, $details);
        $headers = $exception instanceof RateLimiterUnavailableException
            ? ['Retry-After' => '1', 'Cache-Control' => 'no-store']
            : [];
        $event->setResponse(new JsonResponse($error->toArray(), $status, $headers));

        return true;
    }

    /**
     * 503 with Retry-After for a fault that decided nothing about the request, so the client
     * retries it with the same credentials: a RetryableFault, or a lock wait that passed the
     * transaction's lock timeout and rolled the transaction back. The fault is logged; its
     * message is not shown.
     */
    private function respondWithRetryAfter(ExceptionEvent $event, Throwable $exception): bool
    {
        if (!$exception instanceof RetryableFault && !self::isLockTimeout($exception)) {
            return false;
        }

        $this->logger->error('{exception_class}: {message}', [
            'exception_class' => $exception::class,
            'message'         => $exception->getMessage(),
            'exception'       => $exception,
        ]);
        $error = new ApiError('The server is temporarily unavailable; retry after the Retry-After delay.', Response::HTTP_SERVICE_UNAVAILABLE);
        $event->setResponse(new JsonResponse($error->toArray(), Response::HTTP_SERVICE_UNAVAILABLE, [
            'Retry-After' => (string) RetryableFault::RETRY_AFTER_SECONDS,
            'Cache-Control' => 'no-store',
        ]));

        return true;
    }

    private static function isLockTimeout(Throwable $exception): bool
    {
        return $exception instanceof DriverException && $exception->getSQLState() === self::LOCK_NOT_AVAILABLE;
    }

    /**
     * A 401 or 403, including the firewall's refusals, as the shared error envelope with a
     * generic message in the request's language. The exception's own message is not shown.
     */
    private function respondToRefusal(ExceptionEvent $event, Throwable $exception): bool
    {
        if (!$exception instanceof HttpExceptionInterface) {
            return false;
        }

        $key = match ($exception->getStatusCode()) {
            Response::HTTP_UNAUTHORIZED => 'errors.unauthorized.default',
            Response::HTTP_FORBIDDEN => 'errors.forbidden.default',
            default => null,
        };
        if ($key === null) {
            return false;
        }

        $status = $exception->getStatusCode();
        $error = new ApiError($this->translator->trans($key, [], 'messages', $this->locale($event)), $status);
        $event->setResponse(new JsonResponse($error->toArray(), $status, $exception->getHeaders()));

        return true;
    }

    private function locale(ExceptionEvent $event): string
    {
        return RequestLocale::of($event->getRequest()->attributes->get(RequestLocale::ATTRIBUTE));
    }

    private function getSafeMessage(Throwable $exception, int $status): string
    {
        if ($status < 500) {
            return $exception->getMessage();
        }

        return 'An unexpected error occurred.';
    }
}
