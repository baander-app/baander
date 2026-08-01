<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener;

use App\QoL\Domain\Exception\StreamBudgetExhausted;
use App\Shared\Interface\DTO\ApiError;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
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
        $exception = $event->getThrowable();

        if ($exception instanceof StreamBudgetExhausted) {
            $event->setResponse(new JsonResponse(
                $exception->toResponseData(),
                Response::HTTP_SERVICE_UNAVAILABLE,
            ));
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

    private function getSafeMessage(Throwable $exception, int $status): string
    {
        if ($status < 500) {
            return $exception->getMessage();
        }

        return 'An unexpected error occurred.';
    }
}
