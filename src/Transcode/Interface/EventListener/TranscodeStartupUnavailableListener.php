<?php

declare(strict_types=1);

namespace App\Transcode\Interface\EventListener;

use App\Shared\Interface\DTO\ApiError;
use App\Transcode\Application\Exception\TranscodeStartupUnavailableException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 20)]
final class TranscodeStartupUnavailableListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        // Only unwrap a single failure at each level. A mixed handler failure
        // must retain its generic error classification.
        while ($exception instanceof HandlerFailedException) {
            $failures = $exception->getWrappedExceptions();
            if (count($failures) !== 1) {
                return;
            }
            $exception = reset($failures);
        }
        if (!$exception instanceof TranscodeStartupUnavailableException) {
            return;
        }

        $error = new ApiError('Transcode startup is temporarily unavailable. Please retry.', Response::HTTP_SERVICE_UNAVAILABLE);
        $event->setResponse(new JsonResponse($error->toArray(), Response::HTTP_SERVICE_UNAVAILABLE, [
            'Retry-After' => '2',
            'Cache-Control' => 'no-store',
        ]));
    }
}
