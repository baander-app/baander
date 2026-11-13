<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Interface\Controller;

use App\Auth\Infrastructure\Security\ApiRateLimitListener;
use App\Catalog\Application\Dto\VideoSourceMetadata;
use App\Catalog\Application\Port\VideoPortInterface;
use App\Media\Application\Port\MediaReadScopeProviderInterface;
use App\Media\Application\Port\PlaybackMediaAccessInterface;
use App\Media\Application\Port\StreamPortInterface;
use App\Media\Interface\Controller\OriginalMediaController;
use App\Media\Domain\Model\TrackStreamMetadata;
use App\Transcode\Application\Exception\AudioRenditionBusyException;
use App\Transcode\Application\Port\AudioRendition;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\RateLimiterUnavailableException;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Domain\ValueObject\MediaReadScope;
use App\Transcode\Application\Port\AudioRenditionPortInterface;
use App\Transcode\Application\Service\MediaExecutionPolicy;
use App\Transcode\Infrastructure\Auth\HmacStreamAuthAdapter;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

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

    protected function tearDown(): void
    {
        unlink($this->file);
    }

    public function testSignedVideoDeliversOnlyRequestedBytes(): void
    {
        $request = Request::create($this->url());
        $request->headers->set('Range', 'bytes=2-5');
        $request->headers->set('If-Range', gmdate('D, d M Y H:i:s', filemtime($this->file)) . ' GMT');
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

    /** @return iterable<string, array{string, string, bool}> */
    public static function signedOriginalRequests(): iterable
    {
        foreach (['video', 'track'] as $resource) {
            foreach (['GET', 'HEAD'] as $method) {
                foreach ([false, true] as $unavailable) {
                    yield $resource . ' ' . $method . ($unavailable ? ' unavailable' : ' exhausted') => [$resource, $method, $unavailable];
                }
            }
        }
    }

    #[DataProvider('signedOriginalRequests')]
    public function testSignedOriginalMediaSurvivesGenericBudgetFailure(string $resource, string $method, bool $unavailable): void
    {
        $factory = $this->createMock(RateLimiterFactoryInterface::class);
        if ($unavailable) {
            $factory->method('create')->willThrowException(new RateLimiterUnavailableException());
        } else {
            $limiter = $this->createStub(LimiterInterface::class);
            $limiter->method('consume')->willReturn(new RateLimit(0, new \DateTimeImmutable('+30 seconds'), false, 1));
            $factory->method('create')->willReturn($limiter);
        }
        $factory->expects(self::never())->method('create');
        $events = new EventDispatcher();
        $listener = new ApiRateLimitListener($factory, $factory, new TokenStorage(), new NullLogger(), 'prod');
        $events->addListener(KernelEvents::REQUEST, $listener->onKernelRequest(...), 7);
        $path = $resource === 'video' ? '/api/stream/video' : '/api/stream/original/track';
        $url = $this->signatures->signUrl($path . '?' . http_build_query(['id' => $this->video->toString(), 'owner' => $this->owner->toString()]))['url'];
        $request = Request::create($url, $method);
        $request->attributes->set('_route', 'stream_original_' . $resource);
        $request->attributes->set('_controller', $this->controller()->{$resource}(...));
        if ($method === 'GET') {
            $request->headers->set('Range', 'bytes=2-5');
        }
        $kernel = new HttpKernel($events, new ControllerResolver(), new RequestStack(), new ArgumentResolver());
        $response = $kernel->handle($request);
        $response->prepare($request);

        self::assertSame($method === 'GET' ? 206 : 200, $response->getStatusCode());
        self::assertSame($method === 'GET' ? '4' : '10', $response->headers->get('Content-Length'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));

        $invalid = Request::create($url . 'x', $method);
        $invalid->attributes->set('_route', 'stream_original_' . $resource);
        $invalid->attributes->set('_controller', $this->controller()->{$resource}(...));
        self::assertSame(403, $kernel->handle($invalid)->getStatusCode());
    }

    public function testMissingOrTamperedSignaturesCannotReadVideo(): void
    {
        $controller = $this->controller();
        self::assertSame(403, $controller->video(Request::create('/api/stream/video?id=' . $this->video))->getStatusCode());
        self::assertSame(403, $controller->video(Request::create($this->url() . 'x'))->getStatusCode());
        $url = str_replace($this->owner->toString(), (new Uuid())->toString(), $this->url());
        self::assertSame(403, $controller->video(Request::create($url))->getStatusCode());
    }

    #[DataProvider('copyStates')]
    public function testSignedCopyRechecksAccessAndServesCompletedMp4Ranges(bool $cached): void
    {
        $access = $this->createMock(PlaybackMediaAccessInterface::class);
        $access->expects(self::once())->method('assertTrackAccess')->with($this->owner, $this->video);
        $audio = $this->createMock(AudioRenditionPortInterface::class);
        $artifact = AudioRendition::complete(AudioRenditionFormat::Aac, $this->file, 'audio/mp4');
        $audio->expects(self::once())->method('findCompleteCopy')->with($this->video->toString(), $this->file, '0123456789abcdef', 3)->willReturn($cached ? $artifact : null);
        $audio->expects($cached ? self::never() : self::once())->method('openCopy')->with($this->video->toString(), $this->file, '0123456789abcdef', 3)->willReturn($artifact);
        $request = Request::create($this->copyUrl());
        $request->headers->set('Range', 'bytes=2-5');
        $response = $this->controller($access, $audio)->track($request);
        $response->prepare($request);
        self::assertSame(206, $response->getStatusCode());
        self::assertSame('audio/mp4', $response->headers->get('Content-Type'));
        self::assertSame('bytes 2-5/10', $response->headers->get('Content-Range'));
    }

    /** @return iterable<string, array{bool}> */
    public static function copyStates(): iterable
    {
        yield 'fresh' => [false];
        yield 'complete cache' => [true];
    }

    public function testCopyWaitingOnBusyAudioSlotsAnswers503WithRetryAfter(): void
    {
        $audio = $this->createMock(AudioRenditionPortInterface::class);
        $audio->method('findCompleteCopy')->willReturn(null);
        $audio->expects(self::once())->method('openCopy')
            ->willThrowException(new AudioRenditionBusyException('Every audio slot stayed busy for the whole start wait.'));
        $response = $this->controller(audio: $audio)->track(Request::create($this->copyUrl()));
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('5', $response->headers->get('Retry-After'));
        $error = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($error);
        self::assertSame('Every audio slot is busy. Try again shortly.', $error['error']['message'] ?? null);
        self::assertSame(['reason' => 'audio_slots_busy'], $error['error']['details'] ?? null);
    }

    public function testTamperedCopySelectionCannotAdmitWork(): void
    {
        $audio = $this->createMock(AudioRenditionPortInterface::class);
        $audio->expects(self::never())->method('openCopy');
        $request = Request::create(str_replace('index=3', 'index=4', $this->copyUrl()));
        self::assertSame(403, $this->controller(audio: $audio)->track($request)->getStatusCode());
    }

    public function testValidSignatureCannotCopyAnUnselectedAudioStream(): void
    {
        $audio = $this->createMock(AudioRenditionPortInterface::class);
        $audio->expects(self::never())->method('openCopy');
        $request = Request::create($this->copyUrl(4));
        self::assertSame(409, $this->controller(audio: $audio)->track($request)->getStatusCode());
    }

    private function copyUrl(int $index = 3): string
    {
        return $this->signatures->signUrl('/api/stream/original/track?' . http_build_query([
            'id' => $this->video->toString(), 'owner' => $this->owner->toString(), 'format' => 'aac', 'mode' => 'copy', 'container' => 'mp4', 'index' => $index,
        ]))['url'];
    }

    private function url(): string
    {
        return $this->signatures->signUrl('/api/stream/video?' . http_build_query(['id' => $this->video->toString(), 'owner' => $this->owner->toString()]))['url'];
    }

    private function controller(?PlaybackMediaAccessInterface $access = null, ?AudioRenditionPortInterface $audio = null): OriginalMediaController
    {
        $scopes = $this->createStub(MediaReadScopeProviderInterface::class);
        $scopes->method('current')->willReturn(MediaReadScope::none());
        $scopes->method('forUser')->willReturn(MediaReadScope::authenticated($this->owner, LibraryReadScope::unrestricted()));
        $videos = $this->createStub(VideoPortInterface::class);
        $videos->method('getSourceMetadata')->willReturn(new VideoSourceMetadata(new Uuid(), $this->video->toString(), $this->file, 'video.mp4', 10, 'video/mp4', 'hash', 'mov,mp4', 'h264', 0, [], 1920, 1080, 24.0, 10.0));
        $tracks = $this->createStub(StreamPortInterface::class);
        $tracks->method('getTrackMetadata')->willReturn(new TrackStreamMetadata(
            $this->video->toString(),
            'audio.aac',
            $this->file,
            'audio/aac',
            10,
            'aac',
            128000,
            48000,
            2,
            10.0,
            'aac',
            3,
            '0123456789abcdef',
        ));
        $tracks->method('resolveTrackPath')->willReturn($this->file);
        return new OriginalMediaController(new MediaExecutionPolicy(new DeploymentProfile()), $this->createStub(SystemSettingsPortInterface::class), $scopes, $access ?? $this->createStub(PlaybackMediaAccessInterface::class), $videos, $tracks, $audio ?? $this->createStub(AudioRenditionPortInterface::class), $this->signatures);
    }
}
