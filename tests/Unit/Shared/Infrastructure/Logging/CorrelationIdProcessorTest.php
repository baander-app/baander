<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Logging;

use App\Shared\Infrastructure\Logging\BoundedWebProcessor;
use App\Shared\Infrastructure\Logging\CorrelationIdProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class CorrelationIdProcessorTest extends TestCase
{
    private static function record(): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'message');
    }

    private static function processorFor(string $correlationId): CorrelationIdProcessor
    {
        $requests = new RequestStack();
        $requests->push(Request::create('https://baander.app/api/ping', server: ['HTTP_X_BAANDER_CORRELATION_ID' => $correlationId]));

        return new CorrelationIdProcessor($requests);
    }

    public function testAnOrdinaryCorrelationIdIsKept(): void
    {
        $processor = self::processorFor('client-request-0001');

        self::assertSame('client-request-0001', $processor(self::record())->extra['correlation_id']);
        self::assertSame('client-request-0001', $processor->getCurrentCorrelationId());
    }

    /** @return iterable<string, array{string}> */
    public static function unacceptableIds(): iterable
    {
        yield '10,000 bytes' => [str_repeat('a', 10_000)];
        yield '513 bytes' => [str_repeat('a', 513)];
        yield 'a control character' => ["forged\x1b[31mred"];
        yield 'a line break' => ["first\nsecond"];
    }

    #[DataProvider('unacceptableIds')]
    public function testAnOverLongOrBinaryCorrelationIdIsReplacedForTheRecordAndTheMessengerStamp(string $header): void
    {
        $processor = self::processorFor($header);

        $correlationId = $processor(self::record())->extra['correlation_id'];

        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $correlationId);
        // CorrelationIdMiddleware stamps outgoing messages with this value.
        self::assertSame($correlationId, $processor->getCurrentCorrelationId());
    }

    public function testA512ByteCorrelationIdIsKept(): void
    {
        $header = str_repeat('a', CorrelationIdProcessor::MAXIMUM_BYTES);

        self::assertSame($header, self::processorFor($header)(self::record())->extra['correlation_id']);
    }

    public function testTheWebProcessorCutsTheUrlAndTheReferrerTo2048Bytes(): void
    {
        $processor = new BoundedWebProcessor();
        $url = '/api/search?q=' . str_repeat('é', 3000);
        $request = Request::create('https://baander.app' . $url, server: ['HTTP_REFERER' => 'https://baander.app/' . str_repeat('r', 5000)]);
        $processor->onKernelRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));

        $extra = $processor(self::record())->extra;

        self::assertSame(2048, strlen($extra['referrer']));
        self::assertLessThanOrEqual(2048, strlen($extra['url']));
        self::assertGreaterThan(2045, strlen($extra['url']));
        self::assertTrue(mb_check_encoding($extra['url'], 'UTF-8'));
        self::assertStringStartsWith('/api/search?q=', $extra['url']);
        self::assertSame(['url', 'ip', 'http_method', 'server', 'referrer'], array_keys($extra));
    }

    public function testEachRequestsInstanceLogsItsOwnUrlAndForgetsItOnReset(): void
    {
        // The Swoole bundle gives each coroutine its own instance of the kernel.reset service.
        [$first, $second] = [new BoundedWebProcessor(), new BoundedWebProcessor()];
        $kernel = $this->createStub(HttpKernelInterface::class);
        $first->onKernelRequest(new RequestEvent($kernel, Request::create('https://baander.app/api/first'), HttpKernelInterface::MAIN_REQUEST));
        $second->onKernelRequest(new RequestEvent($kernel, Request::create('https://baander.app/api/second'), HttpKernelInterface::MAIN_REQUEST));
        $second->onKernelRequest(new RequestEvent($kernel, Request::create('https://baander.app/api/sub-request'), HttpKernelInterface::SUB_REQUEST));

        self::assertSame('/api/first', $first(self::record())->extra['url']);
        self::assertSame('/api/second', $second(self::record())->extra['url']);
        $first->reset();
        self::assertSame([], $first(self::record())->extra);
    }
}
