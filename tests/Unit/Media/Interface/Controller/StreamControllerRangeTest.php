<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Interface\Controller;

use App\Media\Application\Port\MediaReadScopeProviderInterface;
use App\Media\Application\Port\StreamPortInterface;
use App\Media\Domain\Model\TrackStreamMetadata;
use App\Media\Interface\Controller\StreamController;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Domain\ValueObject\MediaReadScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class StreamControllerRangeTest extends TestCase
{
    private string $directory;
    private string $file;
    private PublicId $trackId;
    private StreamController $controller;
    private const MODIFIED = 1700000000;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-stream-range-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        $this->file = $this->directory . '/track.bin';
        self::assertSame(10, file_put_contents($this->file, '0123456789'));
        self::assertTrue(touch($this->file, self::MODIFIED));
        $this->trackId = new PublicId();
        $stream = $this->createStub(StreamPortInterface::class);
        $stream->method('getLibraryIdForTrack')->willReturn(new Uuid());
        $stream->method('resolveTrackPath')->willReturn($this->file);
        $stream->method('getTrackMetadata')->willReturn(new TrackStreamMetadata(
            $this->trackId->toString(), 'track.bin', $this->file, 'audio/mpeg', 10,
            null, null, null, null, null,
        ));
        $scopes = $this->createStub(MediaReadScopeProviderInterface::class);
        $scopes->method('current')->willReturn(MediaReadScope::authenticated(
            new Uuid(),
            LibraryReadScope::unrestricted(),
        ));
        $this->controller = new StreamController($stream, $scopes);
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        rmdir($this->directory);
    }

    #[DataProvider('ranges')]
    public function testPreparedTrackResponseEmitsOnlyRequestedBytes(string $range, int $status, string $body, ?string $contentRange): void
    {
        [$response, $actualBody] = $this->respond('GET', ['Range' => $range]);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame($body, $actualBody);
        self::assertSame($contentRange, $response->headers->get('Content-Range'));
        self::assertSame('bytes', $response->headers->get('Accept-Ranges'));
        if ($status !== 416) {
            self::assertSame((string) strlen($body), $response->headers->get('Content-Length'));
        }
        $this->assertProtectedCache($response);
    }

    /** @return iterable<string,array{string,int,string,?string}> */
    public static function ranges(): iterable
    {
        yield 'bounded' => ['bytes=2-5', 206, '2345', 'bytes 2-5/10'];
        yield 'open end' => ['bytes=7-', 206, '789', 'bytes 7-9/10'];
        yield 'suffix' => ['bytes=-3', 206, '789', 'bytes 7-9/10'];
        yield 'oversized end' => ['bytes=7-999', 206, '789', 'bytes 7-9/10'];
        yield 'oversized suffix' => ['bytes=-999', 206, '0123456789', 'bytes 0-9/10'];
        yield 'zero suffix' => ['bytes=-0', 416, '', 'bytes */10'];
        yield 'outside file' => ['bytes=10-', 416, '', 'bytes */10'];
        yield 'empty header' => ['', 200, '0123456789', null];
        yield 'missing bounds' => ['bytes=-', 200, '0123456789', null];
        yield 'reversed' => ['bytes=5-2', 200, '0123456789', null];
        yield 'multiple ranges unsupported' => ['bytes=0-1,4-5', 200, '0123456789', null];
        yield 'huge decimal start' => ['bytes=' . str_repeat('9', 100) . '-', 416, '', 'bytes */10'];
    }

    public function testEmptyTrackRejectsSyntacticallyValidRangeWithoutBody(): void
    {
        self::assertSame(0, file_put_contents($this->file, ''));
        clearstatcache(true, $this->file);
        [$response, $body] = $this->respond('GET', ['Range' => 'bytes=0-1']);
        self::assertSame(416, $response->getStatusCode());
        self::assertSame('', $body);
        self::assertSame('bytes */0', $response->headers->get('Content-Range'));
        $this->assertProtectedCache($response);
    }

    #[DataProvider('validators')]
    public function testIfRangeDeterminesWhetherFullBodyOrRangeIsReturned(string $validator, int $status, string $body): void
    {
        [$response, $actualBody] = $this->respond('GET', ['Range' => 'bytes=2-5', 'If-Range' => $validator]);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame($body, $actualBody);
        self::assertSame($status === 206 ? 'bytes 2-5/10' : null, $response->headers->get('Content-Range'));
        self::assertSame(gmdate('D, d M Y H:i:s', self::MODIFIED) . ' GMT', $response->headers->get('Last-Modified'));
        $this->assertProtectedCache($response);
    }

    /** @return iterable<string,array{string,int,string}> */
    public static function validators(): iterable
    {
        yield 'matching date' => [gmdate('D, d M Y H:i:s', self::MODIFIED) . ' GMT', 206, '2345'];
        yield 'stale date' => [gmdate('D, d M Y H:i:s', self::MODIFIED - 60) . ' GMT', 200, '0123456789'];
        yield 'weak etag' => ['W/"unknown"', 200, '0123456789'];
        yield 'unknown strong etag' => ['"unknown"', 200, '0123456789'];
    }

    #[DataProvider('headRequests')]
    public function testHeadIgnoresRangeAndEmitsNoBody(?string $range): void
    {
        [$response, $body] = $this->respond('HEAD', $range === null ? [] : ['Range' => $range]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $body);
        self::assertSame('10', $response->headers->get('Content-Length'));
        self::assertNull($response->headers->get('Content-Range'));
        $this->assertProtectedCache($response);
    }

    /** @return iterable<string,array{?string}> */
    public static function headRequests(): iterable
    {
        yield 'whole file' => [null];
        yield 'range supplied' => ['bytes=2-5'];
    }

    /** @param array<string,string> $headers
     *  @return array{Response,string}
     */
    private function respond(string $method, array $headers): array
    {
        $request = Request::create('https://api.baander.app/api/stream/track?id=' . $this->trackId->toString(), $method);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $response = $this->controller->streamById($request);
        $response->prepare($request);
        ob_start();
        try {
            $response->sendContent();
            $body = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        return [$response, $body];
    }

    private function assertProtectedCache(Response $response): void
    {
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
    }
}
