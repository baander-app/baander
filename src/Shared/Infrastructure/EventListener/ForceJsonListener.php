<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener;

use App\Shared\Infrastructure\Http\ApiRequestPath;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Forces the request format to JSON for API routes.
 *
 * This ensures that Accept: star/star and missing Accept headers on /api/ routes
 * are treated as application/json, preventing the Symfony router from
 * returning 406 for content negotiation. A percent-encoded API path counts as
 * an API request, because the router matches the decoded path.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
final class ForceJsonListener
{
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!ApiRequestPath::isApi($request)) {
            return;
        }

        if ($request->headers->get('Content-Type') === null) {
            $request->headers->set('Content-Type', 'application/json');
        }

        $accept = $request->headers->get('Accept', '');
        if ($accept === '*/*' || $accept === '') {
            $request->headers->set('Accept', 'application/json');
        }

        $request->setRequestFormat('json');
    }
}
