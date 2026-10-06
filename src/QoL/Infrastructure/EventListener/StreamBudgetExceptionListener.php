<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure\EventListener;

use App\QoL\Domain\Exception\StreamBudgetExhausted;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 20)]
final class StreamBudgetExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof StreamBudgetExhausted) {
            return;
        }

        $event->setResponse(new JsonResponse(
            $exception->toResponseData(),
            Response::HTTP_SERVICE_UNAVAILABLE,
        ));
    }
}
