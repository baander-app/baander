<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Http\{Request, Response};
use SwooleBundle\SwooleBundle\Server\HttpServerConfiguration;
use SwooleBundle\SwooleBundle\Server\RequestHandler\{AdvancedStaticFilesServer, RequestHandler};
use Symfony\Component\Filesystem\Filesystem;

final class BundleStaticFileBoundaryTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function paths(): iterable
    {
        yield 'parent traversal' => ['/../secret.txt', false];
        yield 'external symlink' => ['/asset.txt', false];
        yield 'cached external symlink' => ['/asset.txt', true];
    }

    #[DataProvider('paths')]
    public function testStaticServingCannotEscapeThePublicDirectory(string $uri, bool $warmCache): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-static-' . bin2hex(random_bytes(8));
        $public = $directory . '/public';
        $filesystem = new Filesystem();
        $filesystem->mkdir($public);
        $filesystem->dumpFile($directory . '/secret.txt', 'private');
        $filesystem->dumpFile($public . '/asset.txt', 'public');

        try {
            $configuration = $this->createStub(HttpServerConfiguration::class);
            $configuration->method('hasPublicDir')->willReturn(true);
            $configuration->method('getPublicDir')->willReturn($public);
            $fallback = $this->createMock(RequestHandler::class);
            $fallback->expects(self::once())->method('handle');
            $handler = new AdvancedStaticFilesServer($fallback, $configuration);
            $handler->boot();

            if ($warmCache) {
                $allowed = $this->createMock(Response::class);
                $allowed->expects(self::once())->method('header')->with('Content-Type', 'text/plain')->willReturn(true);
                $allowed->expects(self::once())->method('sendfile')->with($public . '/asset.txt')->willReturn(true);
                $handler->handle(self::request('/asset.txt'), $allowed);
            }

            unlink($public . '/asset.txt');
            self::assertTrue(symlink($directory . '/secret.txt', $public . '/asset.txt'));
            clearstatcache(true);
            $denied = $this->createMock(Response::class);
            $denied->expects(self::never())->method('header');
            $denied->expects(self::never())->method('sendfile');
            $handler->handle(self::request($uri), $denied);
        } finally {
            $filesystem->remove($directory);
        }
    }

    private static function request(string $uri): Request
    {
        $request = new Request();
        $request->server = ['request_method' => 'GET', 'request_uri' => $uri];

        return $request;
    }
}
