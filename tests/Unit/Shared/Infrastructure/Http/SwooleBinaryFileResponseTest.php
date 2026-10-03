<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation\EndResponseProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation\SwooleBinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

final class SwooleBinaryFileResponseTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'baander-range-');
        file_put_contents($this->path, '0123456789');
    }

    protected function tearDown(): void
    {
        unlink($this->path);
    }

    #[DataProvider('ranges')]
    public function testRangePreparation(string $range, int $status, int $offset, int $length, ?string $contentRange): void
    {
        $request = Request::create('https://api.baander.app/api/stream/track');
        $request->headers->set('Range', $range);
        $response = new SwooleBinaryFileResponse($this->path);
        $response->prepare($request);
        $response->prepare($request);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame($offset, $response->getOffset());
        self::assertSame($length, $response->getLength());
        self::assertSame((string) $length, $response->headers->get('Content-Length'));
        self::assertSame($contentRange, $response->headers->get('Content-Range'));
        self::assertSame($range, $request->headers->get('Range'));
    }

    /** @return iterable<string, array{string, int, int, int, ?string}> */
    public static function ranges(): iterable
    {
        yield 'bounded' => ['bytes=2-4', 206, 2, 3, 'bytes 2-4/10'];
        yield 'open ended' => ['bytes=7-', 206, 7, 3, 'bytes 7-9/10'];
        yield 'suffix' => ['bytes=-3', 206, 7, 3, 'bytes 7-9/10'];
        yield 'huge suffix' => ['bytes=-9999999999999999999999999', 206, 0, 10, 'bytes 0-9/10'];
        yield 'huge end' => ['bytes=2-9999999999999999999999999', 206, 2, 8, 'bytes 2-9/10'];
        yield 'leading zeros' => ['bytes=0002-0004', 206, 2, 3, 'bytes 2-4/10'];
        yield 'beyond eof' => ['bytes=10-', 416, 0, 0, 'bytes */10'];
        yield 'huge start' => ['bytes=9999999999999999999999999-', 416, 0, 0, 'bytes */10'];
        yield 'zero suffix' => ['bytes=-0', 416, 0, 0, 'bytes */10'];
        yield 'malformed' => ['bytes=abc-def', 200, 0, 10, null];
        yield 'empty' => ['bytes=-', 200, 0, 10, null];
        yield 'multiple' => ['bytes=0-1,4-5', 200, 0, 10, null];
        yield 'wrong unit' => ['items=0-2', 200, 0, 10, null];
        yield 'reversed' => ['bytes=4-2', 200, 0, 10, null];
        yield 'huge reversed' => ['bytes=9999999999999999999999999-8888888888888888888888888', 200, 0, 10, null];
    }

    #[DataProvider('validators')]
    public function testIfRange(string $ifRange, bool $weakEtag, int $status): void
    {
        $response = new SwooleBinaryFileResponse($this->path);
        $response->setEtag('fixture', $weakEtag);
        $response->setLastModified(new \DateTimeImmutable('2026-10-03T12:00:00Z'));
        $request = Request::create('https://api.baander.app/api/stream/track');
        $request->headers->set('Range', 'bytes=2-4');
        $request->headers->set('If-Range', $ifRange);
        $response->prepare($request);
        $response->prepare($request);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame($status === 206 ? 3 : 10, $response->getLength());
    }

    /** @return iterable<string, array{string, bool, int}> */
    public static function validators(): iterable
    {
        yield 'matching strong etag' => ['"fixture"', false, 206];
        yield 'different etag' => ['"other"', false, 200];
        yield 'weak matching etag' => ['W/"fixture"', true, 200];
        yield 'weak request etag' => ['W/"fixture"', false, 200];
        yield 'matching date' => ['Sat, 03 Oct 2026 12:00:00 GMT', false, 206];
        yield 'stale date' => ['Fri, 02 Oct 2026 12:00:00 GMT', false, 200];
    }

    public function testHeadKeepsFullLengthAndDoesNotSendFile(): void
    {
        $response = new SwooleBinaryFileResponse($this->path);
        $request = Request::create('https://api.baander.app/api/stream/track', 'HEAD');
        $request->headers->set('Range', 'bytes=2-4');
        $response->prepare($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('10', $response->headers->get('Content-Length'));
        self::assertSame(0, $response->getLength());
        $transport = $this->createMock(SwooleResponse::class);
        $transport->expects(self::never())->method('sendfile');
        $transport->expects(self::once())->method('end')->with();
        (new EndResponseProcessor())->process($response, $transport);
    }

    public function testPartialEmissionUsesPreparedOffsetAndLength(): void
    {
        $request = Request::create('https://api.baander.app/api/stream/track');
        $request->headers->set('Range', 'bytes=2-4');
        $response = (new SwooleBinaryFileResponse($this->path))->prepare($request);
        $transport = $this->createMock(SwooleResponse::class);
        $transport->expects(self::once())->method('sendfile')->with($this->path, 2, 3);
        $transport->expects(self::never())->method('end');
        (new EndResponseProcessor())->process($response, $transport);
    }

    public function testEmptyFileAndUnsatisfiableRangeHaveNoBody(): void
    {
        file_put_contents($this->path, '');
        clearstatcache(true, $this->path);
        foreach ([null, 'bytes=0-', 'bytes=-3'] as $range) {
            $request = Request::create('https://api.baander.app/api/stream/track');
            if ($range !== null) {
                $request->headers->set('Range', $range);
            }
            $response = (new SwooleBinaryFileResponse($this->path))->prepare($request);
            self::assertSame($range === null ? 200 : 416, $response->getStatusCode());
            self::assertSame('0', $response->headers->get('Content-Length'));
            $transport = $this->createMock(SwooleResponse::class);
            $transport->expects(self::never())->method('sendfile');
            $transport->expects(self::once())->method('end')->with();
            (new EndResponseProcessor())->process($response, $transport);
        }
    }

    public function testPostIgnoresRangeAndFullEmissionHasAnExplicitLength(): void
    {
        $request = Request::create('https://api.baander.app/api/stream/track', 'POST');
        $request->headers->set('Range', 'bytes=2-4');
        $response = (new SwooleBinaryFileResponse($this->path))->prepare($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(10, $response->getLength());
        self::assertFalse($response->headers->has('Content-Range'));
        $transport = $this->createMock(SwooleResponse::class);
        $transport->expects(self::once())->method('sendfile')->with($this->path, 0, 10);
        $transport->expects(self::never())->method('end');
        (new EndResponseProcessor())->process($response, $transport);
    }

    public function testNotModifiedHasNoBody(): void
    {
        $response = new SwooleBinaryFileResponse($this->path);
        $response->setNotModified();
        $response->prepare(Request::create('https://api.baander.app/api/stream/track'));
        self::assertSame(0, $response->getLength());
        $transport = $this->createMock(SwooleResponse::class);
        $transport->expects(self::never())->method('sendfile');
        $transport->expects(self::once())->method('end')->with();
        (new EndResponseProcessor())->process($response, $transport);
    }
}
