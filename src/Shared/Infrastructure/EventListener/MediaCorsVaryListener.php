<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/** Keep partial media responses separated by origin, including requests without Origin. */
#[AsEventListener(event: 'kernel.response', priority: -255)]
final class MediaCorsVaryListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = rawurldecode($event->getRequest()->getPathInfo());
        if (!str_starts_with($path, '/api/images/')
            && !in_array(rtrim($path, '/'), ['/api/stream/track', '/api/stream/media'], true)) {
            return;
        }

        // Nelmio's cacheable-response listener skips 206 responses. Origin can
        // still change their CORS headers, even when this request has no origin.
        $response = $event->getResponse();
        $vary = array_map(strtolower(...), $response->getVary());
        if (!in_array('*', $vary, true) && !in_array('origin', $vary, true)) {
            $response->setVary('Origin', false);
        }
    }
}
