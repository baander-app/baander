<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Interface\Controller;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\PlaybackPortInterface;
use App\Transcode\Application\Port\StreamAuthPortInterface;
use App\Transcode\Interface\Controller\StreamSigningController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class StreamSigningSecurityTest extends TestCase
{
    #[DataProvider('invalidRequests')]
    public function testInvalidSigningRequestsNeverStartPlayback(string $body): void
    {
        $auth = $this->createMock(StreamAuthPortInterface::class);
        $auth->expects($this->never())->method('signUrl');
        $playback = $this->createMock(PlaybackPortInterface::class);
        $playback->expects($this->never())->method('start');
        $controller = new StreamSigningController($auth, $playback);
        self::assertSame(400, $controller->sign(Request::create('/', 'POST', content: $body))->getStatusCode());
    }

    /** @return iterable<array{string}> */
    public static function invalidRequests(): iterable
    {
        yield ['{'];
        yield ['null'];
        yield ['{"path":"/api/admin"}'];
        yield ['{"path":"https://evil.example/path"}'];
        yield ['{"path":[]}'];
        $path = '/api/transcode/00000000-0000-4000-8000-000000000001/master.m3u8';
        foreach ([0, -1, 86401, '60', 1.5, null, []] as $duration) {
            yield [json_encode(['path' => $path, 'expiresInSeconds' => $duration], JSON_THROW_ON_ERROR)];
        }
        yield [json_encode(['path' => $path.'?unexpected=1'], JSON_THROW_ON_ERROR)];
    }

    public function testSigningCannotBypassLibraryAccess(): void
    {
        $id = new Uuid();
        $auth = $this->createMock(StreamAuthPortInterface::class);
        $auth->expects($this->never())->method('signUrl');
        $playback = $this->createMock(PlaybackPortInterface::class);
        $playback->expects($this->once())->method('start')->with($this->equalTo($id))
            ->willThrowException(new AccessDeniedException());
        $controller = new StreamSigningController($auth, $playback);
        $this->expectException(AccessDeniedException::class);
        $controller->sign(Request::create('/', 'POST', content: json_encode(['path' => '/api/transcode/'.$id.'/master.m3u8'], JSON_THROW_ON_ERROR)));
    }

    public function testSigningDefaultsTo24Hours(): void
    {
        $id = new Uuid();
        $path = '/api/transcode/'.$id.'/master.m3u8';
        $playback = $this->createMock(PlaybackPortInterface::class);
        $playback->expects($this->once())->method('start')->with($this->equalTo($id));
        $auth = $this->createMock(StreamAuthPortInterface::class);
        $auth->expects($this->once())->method('signUrl')->with($path, 86400)
            ->willReturn(['url' => $path.'?sig=s&exp=1', 'sig' => 's', 'exp' => 1]);
        $controller = new StreamSigningController($auth, $playback);
        self::assertSame(200, $controller->sign(Request::create('/', 'POST', content: json_encode(['path' => $path], JSON_THROW_ON_ERROR)))->getStatusCode());
    }
}
