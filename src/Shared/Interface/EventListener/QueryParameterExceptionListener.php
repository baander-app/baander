<?php

declare(strict_types=1);

namespace App\Shared\Interface\EventListener;

use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\Exception\InvalidQueryParameter;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
final class QueryParameterExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof InvalidQueryParameter) {
            return;
        }

        $error = new ApiError($exception->getMessage(), 400, [
            $exception->parameter => [$exception->getMessage()],
        ]);
        $event->setResponse(new JsonResponse($error->toArray(), 400));
    }
}
