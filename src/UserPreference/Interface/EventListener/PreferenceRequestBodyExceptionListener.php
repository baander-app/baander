<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\EventListener;

use App\Shared\Interface\DTO\ApiError;
use App\UserPreference\Interface\Exception\InvalidPreferenceRequestBody;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
final class PreferenceRequestBodyExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof InvalidPreferenceRequestBody) {
            return;
        }

        $status = $exception->getStatusCode();
        $error = new ApiError($exception->getMessage(), $status);
        $event->setResponse(new JsonResponse($error->toArray(), $status));
    }
}
