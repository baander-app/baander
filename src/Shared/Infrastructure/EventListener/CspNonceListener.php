<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
final class CspNonceListener
{
    public const string NONCE_ATTRIBUTE = '_csp_nonce';

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $nonce = bin2hex(random_bytes(16));
        $event->getRequest()->attributes->set(self::NONCE_ATTRIBUTE, $nonce);
    }
}
