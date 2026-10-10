<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener;

use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\HandlerFailure;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Http\RequestLocale;
use App\Shared\Interface\DTO\ApiError;
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

        if ($this->respondWithOutcome($event, $exception) || $this->respondToRefusal($event, $exception)) {
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
        $event->setResponse(new JsonResponse($error->toArray(), $status));

        return true;
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
