<?php

declare(strict_types=1);

namespace App\Auth\Interface\EventListener;

use App\Auth\Application\Exception\CredentialsChangedException;
use App\Shared\Application\Exception\HandlerFailure;
use App\Shared\Application\Http\RequestLocale;
use App\Shared\Interface\DTO\ApiError;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Answers a credential change refused because its session was already ended: 401 with the
 * reason credentials_changed, so the client signs in again instead of retrying. Grants that
 * refuse a stale credential epoch answer in their own protocol and never let the exception
 * reach this listener. It runs before ExceptionSubscriber, which would otherwise answer 500.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 8)]
final readonly class CredentialsChangedListener
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $cause = HandlerFailure::cause($event->getThrowable());
        if (!$cause instanceof CredentialsChangedException) {
            return;
        }

        $locale = $event->getRequest()->attributes->get(RequestLocale::ATTRIBUTE) |> RequestLocale::of(...);
        $error = new ApiError(
            $cause->trans($this->translator, $locale),
            Response::HTTP_UNAUTHORIZED,
            ['reason' => CredentialsChangedException::REASON],
        );
        $event->setResponse(new JsonResponse($error->toArray(), Response::HTTP_UNAUTHORIZED));
    }
}
