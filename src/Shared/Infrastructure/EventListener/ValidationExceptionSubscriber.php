<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener;

use App\Shared\Interface\DTO\ApiError;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
final class ValidationExceptionSubscriber
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof HttpException) {
            return;
        }

        $previous = $exception->getPrevious();
        if (!$previous instanceof ValidationFailedException) {
            return;
        }

        $details = [];
        foreach ($previous->getViolations() as $violation) {
            $details[$violation->getPropertyPath()][] = $violation->getMessage();
        }

        $error = new ApiError(
            message: $this->translator->trans('errors.validation.failed'),
            code: $exception->getStatusCode(),
            details: $details,
        );

        $event->setResponse(new JsonResponse(
            $error->toArray(),
            $exception->getStatusCode(),
        ));
    }
}
