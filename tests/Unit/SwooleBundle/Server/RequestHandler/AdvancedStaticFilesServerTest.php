<?php

declare(strict_types=1);

namespace App\Tests\Unit\SwooleBundle\Server\RequestHandler;

use ArrayObject;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Swoole\Http\{Request, Response};
use SwooleBundle\SwooleBundle\Server\HttpServerConfiguration;
use SwooleBundle\SwooleBundle\Server\RequestHandler\{AdvancedStaticFilesServer, RequestHandler};
use Symfony\Component\Filesystem\Filesystem;

final class AdvancedStaticFilesServerTest extends TestCase
{
    private const string FONT = '/assets/geist-latin-wght-normal-Bx3kQ9aZ.woff2';

    private string $directory;
    private string $public;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander.app-static-' . bin2hex(random_bytes(8));
        $this->public = $this->directory . '/public';
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->public . self::FONT, 'font');
        $filesystem->dumpFile($this->public . '/favicon.svg', '<svg/>');
        $filesystem->dumpFile($this->directory . '/secret.txt', 'private');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testFingerprintedAssetsAreCachedForAYearWithoutRevalidation(): void
    {
        $response = $this->createMock(Response::class);
        $headers = $this->recordHeaders($response);
        $response->expects(self::once())->method('sendfile')->with($this->public . self::FONT)->willReturn(true);

        $this->server($this->neverReached())->handle(self::get(self::FONT), $response);

        self::assertSame(
            ['Content-Type' => 'font/woff2', 'Cache-Control' => 'public, max-age=31536000, immutable'],
            $headers->getArrayCopy(),
        );
    }

    public function testOtherStaticFilesKeepTheirHeaders(): void
    {
        $response = $this->createMock(Response::class);
        $headers = $this->recordHeaders($response);
        $response->expects(self::once())->method('sendfile')->with($this->public . '/favicon.svg')->willReturn(true);

        $this->server($this->neverReached())->handle(self::get('/favicon.svg'), $response);

        self::assertSame(['Content-Type' => 'image/svg+xml'], $headers->getArrayCopy());
    }

    public function testAnAssetPathThatResolvesOutsideThePublicDirectoryIsRefused(): void
    {
        $fallback = $this->createMock(RequestHandler::class);
        $fallback->expects(self::once())->method('handle');
        $response = $this->createMock(Response::class);
        $response->expects(self::never())->method('header');
        $response->expects(self::never())->method('sendfile');

        $this->server($fallback)->handle(self::get('/assets/../../secret.txt'), $response);
    }

    private function server(RequestHandler $fallback): AdvancedStaticFilesServer
    {
        $configuration = $this->createStub(HttpServerConfiguration::class);
        $configuration->method('hasPublicDir')->willReturn(true);
        $configuration->method('getPublicDir')->willReturn($this->public);
        $server = new AdvancedStaticFilesServer($fallback, $configuration);
        $server->boot();

        return $server;
    }

    private function neverReached(): RequestHandler
    {
        $fallback = $this->createMock(RequestHandler::class);
        $fallback->expects(self::never())->method('handle');

        return $fallback;
    }

    /** @return ArrayObject<string, string> */
    private function recordHeaders(Response&MockObject $response): ArrayObject
    {
        /** @var ArrayObject<string, string> $headers */
        $headers = new ArrayObject();
        $response->method('header')->willReturnCallback(static function (string $name, string $value) use ($headers): bool {
            $headers[$name] = $value;

            return true;
        });

        return $headers;
    }

    private static function get(string $uri): Request
    {
        $request = new Request();
        $request->server = ['request_method' => 'GET', 'request_uri' => $uri];

        return $request;
    }
}
