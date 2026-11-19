<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener;

use Pentatrion\ViteBundle\Event\RenderAssetTagEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Gives every script tag the Vite bundle renders the request's Content-Security-Policy
 * nonce, including the development server's client and its inline React refresh preamble.
 */
#[AsEventListener]
final readonly class ViteScriptNonceListener
{
    public function __construct(private RequestStack $requestStack)
    {
    }

    public function __invoke(RenderAssetTagEvent $event): void
    {
        $tag = $event->getTag();
        $nonce = $this->requestStack->getMainRequest()?->attributes->get(SecurityHeadersListener::NONCE_ATTRIBUTE);
        if ($tag->isScriptTag() && is_string($nonce)) {
            $tag->setAttribute('nonce', $nonce);
        }
    }
}
