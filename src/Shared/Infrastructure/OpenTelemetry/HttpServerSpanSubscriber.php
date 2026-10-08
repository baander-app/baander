<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\OpenTelemetry;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use WeakMap;

/**
 * Opens one server span per main HTTP request and ends it on terminate.
 *
 * The span records only the method, the route name, the status and the duration:
 * never the URL, query string or headers, so tokens and signed-URL signatures
 * cannot reach the diagnostics buffer or an exporter. Each span is keyed by its
 * request object, which belongs to the request's coroutine; no ambient OpenTelemetry
 * context is activated, so concurrent coroutines in one worker cannot see each
 * other's span.
 */
final class HttpServerSpanSubscriber implements EventSubscriberInterface
{
    private readonly TracerInterface $tracer;

    /** @var WeakMap<Request, SpanInterface> */
    private WeakMap $spans;

    public function __construct(TracerProviderInterface $tracerProvider)
    {
        $this->tracer = $tracerProvider->getTracer('baander.http');
        $this->spans = new WeakMap();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Before every other listener, so the duration covers the whole request.
            KernelEvents::REQUEST => ['start', 4096],
            KernelEvents::TERMINATE => ['end', -4096],
        ];
    }

    public function start(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $this->spans[$request] = $this->tracer->spanBuilder($request->getMethod())
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setParent(false)
            ->startSpan();
    }

    public function end(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $span = $this->spans[$request] ?? null;
        if ($span === null) {
            return;
        }
        unset($this->spans[$request]);

        $method = $request->getMethod();
        $route = $request->attributes->get('_route');
        $status = $event->getResponse()->getStatusCode();

        $span->setAttribute('http.request.method', $method);
        if (is_string($route)) {
            $span->updateName($method . ' ' . $route);
            $span->setAttribute('baander.route', $route);
        }
        $span->setAttribute('http.response.status_code', $status);
        if ($status >= 500) {
            $span->setStatus(StatusCode::STATUS_ERROR);
        }
        $span->end();
    }
}
