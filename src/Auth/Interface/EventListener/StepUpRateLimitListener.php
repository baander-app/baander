<?php

declare(strict_types=1);

namespace App\Auth\Interface\EventListener;

use App\Auth\Application\Exception\StepUpRateLimitedException;
use App\Shared\Application\Exception\HandlerFailure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers an account that used up its step-up attempts as the request limiters do: 429 with
 * Retry-After. It runs before ExceptionSubscriber, which would otherwise answer 500.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 8)]
final class StepUpRateLimitListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $cause = HandlerFailure::cause($event->getThrowable());
        if ($cause instanceof StepUpRateLimitedException) {
            $event->setThrowable(new TooManyRequestsHttpException($cause->retryAfterSeconds, $cause->getMessage(), $cause));
        }
    }
}
