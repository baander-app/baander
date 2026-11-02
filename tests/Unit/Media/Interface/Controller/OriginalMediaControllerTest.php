<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Interface\Controller;

use App\Catalog\Application\Dto\VideoSourceMetadata;
use App\Catalog\Application\Port\VideoPortInterface;
use App\Media\Application\Port\MediaReadScopeProviderInterface;
use App\Media\Application\Port\PlaybackMediaAccessInterface;
use App\Media\Application\Port\StreamPortInterface;
use App\Media\Interface\Controller\OriginalMediaController;
use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Domain\ValueObject\MediaReadScope;
use App\Transcode\Application\Port\AudioRenditionPortInterface;
use App\Transcode\Application\Service\MediaExecutionPolicy;
use App\Transcode\Infrastructure\Auth\HmacStreamAuthAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class OriginalMediaControllerTest extends TestCase
{
    private string $file;
    private Uuid $owner;
    private PublicId $video;
    private HmacStreamAuthAdapter $signatures;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'baander-original-');
        file_put_contents($this->file, '0123456789');
        $this->owner = new Uuid();
        $this->video = new PublicId();
        $this->signatures = new HmacStreamAuthAdapter('original-test-secret');
    }

    protected function tearDown(): void { unlink($this->file); }

    public function testSignedVideoDeliversOnlyRequestedBytes(): void
    {
        $request = Request::create($this->url());
        $request->headers->set('Range', 'bytes=2-5');
        $request->headers->set('If-Range', gmdate('D, d M Y H:i:s', filemtime($this->file)).' GMT');
        $response = $this->controller()->video($request);
        $response->prepare($request);
        ob_start();
        $response->sendContent();
        $body = ob_get_clean();
        self::assertSame(206, $response->getStatusCode());
        self::assertSame('2345', $body);
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testSignedVideoRechecksRevokedMembership(): void
    {
        $access = $this->createMock(PlaybackMediaAccessInterface::class);
        $access->expects(self::once())->method('assertVideoAccess')->with($this->owner, $this->video)->willThrowException(new ConflictException('revoked'));
        self::assertSame(403, $this->controller($access)->video(Request::create($this->url()))->getStatusCode());
    }

    public function testMissingOrTamperedSignaturesCannotReadVideo(): void
    {
        $controller = $this->controller();
        self::assertSame(403, $controller->video(Request::create('/api/stream/video?id='.$this->video))->getStatusCode());
        self::assertSame(403, $controller->video(Request::create($this->url().'x'))->getStatusCode());
        $url = str_replace($this->owner->toString(), (new Uuid())->toString(), $this->url());
        self::assertSame(403, $controller->video(Request::create($url))->getStatusCode());
    }

    private function url(): string
    {
        return $this->signatures->signUrl('/api/stream/video?'.http_build_query(['id' => $this->video->toString(), 'owner' => $this->owner->toString()]))['url'];
    }

    private function controller(?PlaybackMediaAccessInterface $access = null): OriginalMediaController
    {
        $scopes = $this->createStub(MediaReadScopeProviderInterface::class);
        $scopes->method('current')->willReturn(MediaReadScope::none());
        $scopes->method('forUser')->willReturn(MediaReadScope::authenticated($this->owner, LibraryReadScope::unrestricted()));
        $videos = $this->createStub(VideoPortInterface::class);
        $videos->method('getSourceMetadata')->willReturn(new VideoSourceMetadata(new Uuid(), $this->video->toString(), $this->file, 'video.mp4', 10, 'video/mp4', 'hash', 'mov,mp4', 'h264', 0, [], 1920, 1080, 24.0, 10.0));
        return new OriginalMediaController(new MediaExecutionPolicy(new DeploymentProfile()), $this->createStub(SystemSettingsPortInterface::class), $scopes, $access ?? $this->createStub(PlaybackMediaAccessInterface::class), $videos, $this->createStub(StreamPortInterface::class), $this->createStub(AudioRenditionPortInterface::class), $this->signatures);
    }
}
