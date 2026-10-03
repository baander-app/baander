<?php

declare(strict_types=1);

namespace App\Scheduler\Interface\EventListener;

use App\Scheduler\Domain\Exception\ScheduledJobConflict;
use App\Shared\Interface\DTO\ApiError;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 20)]
final class ScheduledJobConflictListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof ScheduledJobConflict) {
            return;
        }

        $error = new ApiError('Scheduled job changed. Reload it and try again.', Response::HTTP_CONFLICT);
        $event->setResponse(new JsonResponse($error->toArray(), Response::HTTP_CONFLICT));
    }
}
