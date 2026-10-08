<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\OpenTelemetry;

use App\Shared\Infrastructure\OpenTelemetry\HttpServerSpanSubscriber;
use App\Shared\Infrastructure\OpenTelemetry\InMemorySpanProcessor;
use App\Shared\Infrastructure\OpenTelemetry\SpanBridge;
use App\Shared\Infrastructure\OpenTelemetry\TracerProviderFactory;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class HttpServerSpanSubscriberTest extends TestCase
{
    private SpanBridge $bridge;

    protected function setUp(): void
    {
        $this->bridge = new SpanBridge();
        $this->bridge->boot();
        $this->bridge->clear();
    }

    public function testHandledRequestRecordsOneSpanWithMethodRouteStatusAndDurationOnly(): void
    {
        $subscriber = new HttpServerSpanSubscriber($this->tracerProvider(exportEnabled: false));
        $request = Request::create(
            'https://baander.app/api/albums/42?access_token=query-secret&signature=signed-url-signature',
            'POST',
            server: ['HTTP_AUTHORIZATION' => 'Bearer header-secret'],
        );

        $this->handle($subscriber, $request, 'api_album_update', 201);

        $spans = $this->bridge->getRecentSpans();
        self::assertCount(1, $spans);
        $span = $spans[0];
        self::assertSame('POST api_album_update', $span['operation_name']);
        self::assertSame([
            'http.request.method' => 'POST',
            'baander.route' => 'api_album_update',
            'http.response.status_code' => 201,
        ], $span['attributes']);
        self::assertIsInt($span['duration_us']);
        self::assertGreaterThanOrEqual(0, $span['duration_us']);
        self::assertNull($span['parent_span_id']);

        $recorded = json_encode($span, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach (['/api/albums/42', 'access_token', 'query-secret', 'signed-url-signature', 'header-secret', 'baander.app'] as $leak) {
            self::assertStringNotContainsString($leak, $recorded);
        }
    }

    public function testSubRequestsDoNotOpenSpans(): void
    {
        $subscriber = new HttpServerSpanSubscriber($this->tracerProvider(exportEnabled: false));
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/fragment');

        $subscriber->start(new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST));
        $subscriber->end(new TerminateEvent($kernel, $request, new Response()));

        self::assertSame([], $this->bridge->getRecentSpans());
    }

    public function testRequestWithoutRouteIsNamedByMethodAndServerErrorsAreMarked(): void
    {
        $subscriber = new HttpServerSpanSubscriber($this->tracerProvider(exportEnabled: false));

        $this->handle($subscriber, Request::create('/missing'), null, 500);

        $span = $this->bridge->getRecentSpans()[0];
        self::assertSame('GET', $span['operation_name']);
        self::assertSame(['http.request.method' => 'GET', 'http.response.status_code' => 500], $span['attributes']);
        self::assertSame('Error', $span['status_code']);
    }

    public function testConcurrentRequestsKeepTheirOwnSpans(): void
    {
        $subscriber = new HttpServerSpanSubscriber($this->tracerProvider(exportEnabled: false));
        $kernel = $this->createStub(HttpKernelInterface::class);
        $first = Request::create('/first');
        $second = Request::create('/second', 'DELETE');

        $subscriber->start(new RequestEvent($kernel, $first, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->start(new RequestEvent($kernel, $second, HttpKernelInterface::MAIN_REQUEST));
        $second->attributes->set('_route', 'second_route');
        $subscriber->end(new TerminateEvent($kernel, $second, new Response('', 204)));
        $first->attributes->set('_route', 'first_route');
        $subscriber->end(new TerminateEvent($kernel, $first, new Response()));

        self::assertSame(
            ['GET first_route', 'DELETE second_route'],
            array_column($this->bridge->getRecentSpans(), 'operation_name'),
        );
    }

    public function testWithExportDisabledSpansReachTheBufferAndNothingIsExported(): void
    {
        $exporterCreated = false;
        $factory = new TracerProviderFactory(
            new InMemorySpanProcessor($this->bridge),
            false,
            '',
            'baander',
            static function () use (&$exporterCreated): SpanExporterInterface {
                $exporterCreated = true;

                return new InMemoryExporter();
            },
        );
        $provider = $factory->create();

        $this->handle(new HttpServerSpanSubscriber($provider), Request::create('/api/health'), 'health', 200);
        $provider->forceFlush();

        self::assertCount(1, $this->bridge->getRecentSpans());
        self::assertFalse($exporterCreated);
    }

    public function testWithExportEnabledSpansReachTheBufferAndTheExporter(): void
    {
        $exporter = new InMemoryExporter();
        $endpoint = null;
        $factory = new TracerProviderFactory(
            new InMemorySpanProcessor($this->bridge),
            true,
            'http://collector.baander.app:4318/v1/traces',
            'baander',
            static function (string $url) use ($exporter, &$endpoint): SpanExporterInterface {
                $endpoint = $url;

                return $exporter;
            },
        );
        $provider = $factory->create();

        $this->handle(new HttpServerSpanSubscriber($provider), Request::create('/api/health'), 'health', 200);
        $provider->forceFlush();

        self::assertCount(1, $this->bridge->getRecentSpans());
        self::assertSame('http://collector.baander.app:4318/v1/traces', $endpoint);
        self::assertCount(1, $exporter->getSpans());
        self::assertSame('GET health', $exporter->getSpans()[0]->getName());
    }

    private function tracerProvider(bool $exportEnabled): \OpenTelemetry\API\Trace\TracerProviderInterface
    {
        return (new TracerProviderFactory(new InMemorySpanProcessor($this->bridge), $exportEnabled, '', 'baander'))->create();
    }

    private function handle(HttpServerSpanSubscriber $subscriber, Request $request, ?string $route, int $status): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $subscriber->start(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        // The router sets the route name after the span has started.
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }
        $subscriber->end(new TerminateEvent($kernel, $request, new Response('', $status)));
    }
}
