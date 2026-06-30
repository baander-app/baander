<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class DpopNonceResponseListener
{
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $response = $event->getResponse();

        // Avoid overwriting a nonce already set by token endpoints
        if ($response->headers->has('DPoP-Nonce')) {
            return;
        }

        // Informational nonce for clients to include in future proofs.
        // Not stored/validated — only token endpoints do that.
        $response->headers->set('DPoP-Nonce', bin2hex(random_bytes(32)));
    }
}
