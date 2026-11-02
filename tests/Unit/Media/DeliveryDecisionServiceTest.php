<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media;

use App\Catalog\Application\Dto\VideoSourceMetadata;
use App\Catalog\Application\Port\VideoPortInterface;
use App\Media\Application\DTO\DeliveryDestination;
use App\Media\Application\DTO\DeliveryKind;
use App\Media\Application\Port\MediaReadScopeProviderInterface;
use App\Media\Application\Port\PlaybackMediaAccessInterface;
use App\Media\Application\Port\StreamPortInterface;
use App\Media\Application\Service\DeliveryCompatibility;
use App\Media\Application\Service\DeliveryDecisionService;
use App\Media\Domain\Model\TrackStreamMetadata;
use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Domain\ValueObject\MediaReadScope;
use App\Transcode\Application\DTO\CompletedVideoArtifact;
use App\Transcode\Application\DTO\CopyPresentation;
use App\Transcode\Application\Port\CopyPresentationPortInterface;
use App\Transcode\Application\Port\CompletedVideoArtifactPort;
use App\Transcode\Application\Port\AudioRendition;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Transcode\Application\Port\AudioRenditionPortInterface;
use App\Transcode\Application\Service\MediaExecutionPolicy;
use App\Transcode\Application\Settings\TranscodeSettingDefinitions;
use PHPUnit\Framework\TestCase;

final class DeliveryDecisionServiceTest extends TestCase
{
    public function testDashOnlyCopyReturnsTheSelectedManifest(): void
    {
        $videos = $this->createStub(VideoPortInterface::class);
        $videos->method('getSourceMetadata')->willReturn(new VideoSourceMetadata(new Uuid(), (new PublicId())->toString(), '/library/movie.mkv', 'movie.mkv', 100, 'video/x-matroska', 'hash', 'matroska', 'h264', 3,
            [['codec' => 'aac', 'channels' => 2, 'streamIndex' => 8, 'isDefault' => true]], 1920, 1080, 24.0, 100.0, pixFmt: 'yuv420p', colorSpace: ['transfer' => 'bt709']));
        $requestId = (new Uuid())->toString();
        $presentation = new CopyPresentation(new PublicId(), new PublicId(), $requestId);
        $copies = $this->createMock(CopyPresentationPortInterface::class);
        $copies->expects(self::once())->method('start')->willReturn($presentation);
        $videoId = new PublicId();
        $decision = $this->service(videos: $videos, copies: $copies)->video(new Uuid(), $videoId,
            new DeliveryDestination(['mp4'], ['h264'], ['aac'], transports: ['dash']), $requestId);

        self::assertSame(DeliveryKind::Copy, $decision->kind);
        self::assertSame('dash', $decision->selection?->transport);
        self::assertSame('/api/transcode/'.$videoId->toString().'/manifest.mpd', $decision->url);
        self::assertSame($requestId, $decision->requestId);
        self::assertSame($presentation->jobId->toString(), $decision->jobId);
    }

    public function testFailedNativeSourceCanOnlyFallBackToPermittedEncoding(): void
    {
        $metadata = new TrackStreamMetadata('track', 'song.mp3', '/library/song.mp3', 'audio/mpeg', 100, 'mp3', 128000, 48000, 2, 10.0,
            'mp3', 0, '0123456789abcdef');
        $destination = new DeliveryDestination(['mp3'], audioCodecs: ['mp3'], sourcePlaybackFailed: true);
        $full = $this->service(profile: 'full', metadata: $metadata)->track(new Uuid(), new PublicId(), $destination);
        self::assertSame(DeliveryKind::Encoded, $full->kind);
        self::assertSame('browser_rejected_source', $full->reason);
        $household = $this->service(metadata: $metadata)->track(new Uuid(), new PublicId(), $destination);
        self::assertSame(DeliveryKind::Incompatible, $household->kind);
        self::assertSame('browser_rejected_source', $household->reason);
        $disabled = $this->service(profile: 'full', enabled: false, metadata: $metadata)->track(new Uuid(), new PublicId(), $destination);
        self::assertSame(DeliveryKind::Incompatible, $disabled->kind);
        self::assertSame('audio_transcoding_disabled', $disabled->reason);
    }

    public function testEmbeddedSubtitlesKeepTheirAdaptiveRenditions(): void
    {
        $videos = $this->createStub(VideoPortInterface::class);
        $videos->method('getSourceMetadata')->willReturn(new VideoSourceMetadata(
            new Uuid(), (new PublicId())->toString(), '/library/movie.mp4', 'movie.mp4', 100,
            'video/mp4', 'hash', 'mp4', 'h264', 0,
            [['codec' => 'aac', 'channels' => 2, 'streamIndex' => 1, 'isDefault' => true]],
            1920, 1080, 24.0, 100.0, pixFmt: 'yuv420p', colorSpace: ['transfer' => 'bt709'],
            hasSubtitles: true,
        ));
        $service = $this->service(videos: $videos);
        $decision = $service->video(new Uuid(), new PublicId(), new DeliveryDestination(['mp4'], ['h264'], ['aac'], transports: ['file', 'hls']));
        self::assertSame(DeliveryKind::Copy, $decision->kind);
        self::assertSame('hls', $decision->selection?->transport);
        $unsupported = $service->video(new Uuid(), new PublicId(), new DeliveryDestination(['mp4'], ['h264'], ['aac']));
        self::assertSame(DeliveryKind::Incompatible, $unsupported->kind);
        self::assertSame('subtitle_delivery_unsupported', $unsupported->reason);
    }

    public function testAuthorizationRunsBeforeMetadataLookup(): void
    {
        $access = $this->createStub(PlaybackMediaAccessInterface::class);
        $access->method('assertVideoAccess')->willThrowException(new ConflictException('Denied.'));
        $videos = $this->createMock(VideoPortInterface::class);
        $videos->expects(self::never())->method('getSourceMetadata');
        $this->expectException(ConflictException::class);
        $this->service($access, $videos)->video(new Uuid(), new PublicId(), new DeliveryDestination(['mp4'], ['h264'], ['aac']));
    }

    public function testSelectedAbsoluteStreamIndexMustExist(): void
    {
        $videos = $this->createStub(VideoPortInterface::class);
        $videos->method('getSourceMetadata')->willReturn(new VideoSourceMetadata(new Uuid(), (new PublicId())->toString(), '/library/movie.mkv', 'movie.mkv', 100, 'video/x-matroska', 'hash', 'matroska', 'h264', 3,
            [['codec' => 'aac', 'channels' => 2, 'streamIndex' => 8, 'isDefault' => true]], 1920, 1080, 24.0, 100.0, pixFmt: 'yuv420p', colorSpace: ['primaries' => 'bt709', 'transfer' => 'bt709', 'matrix' => 'bt709']));
        $service = $this->service($this->createStub(PlaybackMediaAccessInterface::class), $videos);
        self::assertSame('audio_stream_unavailable', $service->video(new Uuid(), new PublicId(), new DeliveryDestination(['mp4'], ['h264'], ['aac'], audioStreamIndex: 0))->reason);
        $copy = $service->video(new Uuid(), new PublicId(), new DeliveryDestination(['mp4'], ['h264'], ['aac'], audioStreamIndex: 8, transports: ['hls']));
        self::assertSame(DeliveryKind::Copy, $copy->kind);
        self::assertSame(8, $copy->selection?->audioStreamIndex);
        self::assertNull($copy->url);
    }

    public function testAlternateAudioCannotUseTheUnchangedOriginal(): void
    {
        $videos = $this->createStub(VideoPortInterface::class);
        $videos->method('getSourceMetadata')->willReturn(new VideoSourceMetadata(new Uuid(), (new PublicId())->toString(), '/library/movie.mp4', 'movie.mp4', 100, 'video/mp4', 'hash', 'mov,mp4,m4a,3gp,3g2,mj2', 'h264', 3,
            [['codec' => 'aac', 'channels' => 2, 'streamIndex' => 8, 'isDefault' => true],
             ['codec' => 'aac', 'channels' => 2, 'streamIndex' => 11, 'isDefault' => false]],
            1920, 1080, 24.0, 100.0, pixFmt: 'yuv420p', colorSpace: ['transfer' => 'bt709']));
        $service = $this->service(videos: $videos);
        $default = $service->video(new Uuid(), new PublicId(), new DeliveryDestination(['mp4'], ['h264'], ['aac'], audioStreamIndex: 8));
        self::assertSame(DeliveryKind::Original, $default->kind);
        $alternate = $service->video(new Uuid(), new PublicId(), new DeliveryDestination(['mp4'], ['h264'], ['aac'], audioStreamIndex: 11, transports: ['file', 'hls']));
        self::assertSame(DeliveryKind::Copy, $alternate->kind);
        self::assertSame(11, $alternate->selection?->audioStreamIndex);
        self::assertNull($alternate->url);
        $unsupported = $service->video(new Uuid(), new PublicId(), new DeliveryDestination(['mp4'], ['h264'], ['aac'], audioStreamIndex: 11));
        self::assertSame(DeliveryKind::Incompatible, $unsupported->kind);
        self::assertSame('audio_stream_selection_requires_remux', $unsupported->reason);
        $full = $this->service(videos: $videos, profile: 'full')->video(new Uuid(), new PublicId(), new DeliveryDestination(['mp4'], ['h264'], ['aac'], audioStreamIndex: 11));
        self::assertSame(DeliveryKind::Incompatible, $full->kind);
    }

    public function testCompatibleCachedAudioIsReadWithoutAdmission(): void
    {
        $audio = $this->createMock(AudioRenditionPortInterface::class);
        $audio->expects(self::never())->method('open');
        $audio->expects(self::once())->method('findComplete')->willReturn(AudioRendition::complete(AudioRenditionFormat::Mp3, '/cache/audio.mp3'));
        $decision = $this->service(audio: $audio)->track(new Uuid(), new PublicId(), new DeliveryDestination(['mp3'], audioCodecs: ['mp3']));
        self::assertSame(DeliveryKind::Cached, $decision->kind);
        self::assertStringContainsString('format=mp3', (string) $decision->url);
    }

    public function testHouseholdCanReadCompatibleCompleteAudioWithEncodingToggleOff(): void
    {
        $audio = $this->createMock(AudioRenditionPortInterface::class);
        $audio->expects(self::never())->method('open');
        $audio->expects(self::once())->method('findComplete')->willReturn(AudioRendition::complete(AudioRenditionFormat::Mp3, '/cache/audio.mp3'));
        $result = $this->service(audio: $audio, enabled: false)->track(new Uuid(), new PublicId(), new DeliveryDestination(['mp3'], audioCodecs: ['mp3']));
        self::assertSame(DeliveryKind::Cached, $result->kind);
    }

    public function testScannedAacSelectsOwnedMp4CopyWithItsAbsoluteStream(): void
    {
        $metadata = new TrackStreamMetadata('track', 'song.aac', '/library/song.aac', 'audio/aac', 100, 'aac', 128000, 48000, 2, 10.0,
            'aac', 3, '0123456789abcdef');
        $audio = $this->createMock(AudioRenditionPortInterface::class);
        $audio->expects(self::never())->method('open');
        $audio->expects(self::never())->method('openCopy');
        $audio->expects(self::once())->method('findCompleteCopy')->with(self::anything(), '/library/song.aac', '0123456789abcdef', 3)->willReturn(null);
        $decision = $this->service(audio: $audio, enabled: false, metadata: $metadata)->track(new Uuid(), new PublicId(), new DeliveryDestination(['mp4'], audioCodecs: ['aac']));
        self::assertSame(DeliveryKind::Copy, $decision->kind);
        self::assertStringContainsString('mode=copy', (string) $decision->url);
        self::assertStringContainsString('index=3', (string) $decision->url);
    }

    public function testAudioToggleSkipsCacheAndReturnsTerminalReason(): void
    {
        $audio = $this->createMock(AudioRenditionPortInterface::class);
        $audio->expects(self::never())->method('findComplete');
        $decision = $this->service(audio: $audio, enabled: false, profile: 'full')->track(new Uuid(), new PublicId(), new DeliveryDestination(['mp3'], audioCodecs: ['mp3']));
        self::assertSame(DeliveryKind::Incompatible, $decision->kind);
        self::assertSame('audio_transcoding_disabled', $decision->reason);
    }

    public function testCompleteVideoArtifactMustMatchSelectedStreamsAndDestination(): void
    {
        $videos = $this->createStub(VideoPortInterface::class);
        $videos->method('getSourceMetadata')->willReturn(new VideoSourceMetadata(new Uuid(), (new PublicId())->toString(), '/library/movie.mkv', 'movie.mkv', 100, 'video/x-matroska', 'hash', 'matroska', 'h264', 3,
            [['codec' => 'aac', 'channels' => 2, 'streamIndex' => 8, 'isDefault' => true]], 1920, 1080, 24.0, 100.0, pixFmt: 'yuv420p', colorSpace: ['transfer' => 'bt709']));
        $artifacts = $this->createStub(CompletedVideoArtifactPort::class);
        $artifacts->method('findComplete')->willReturn([
            new CompletedVideoArtifact('/wrong-stream', 'mp4', 'h264', 'aac', 1920, 1080, 2, 3, 1, 8, false, 'hls', 'yuv420p', new PublicId(), 1),
            new CompletedVideoArtifact('/wrong-bit-depth', 'mp4', 'h264', 'aac', 1920, 1080, 2, 3, 8, 10, false, 'hls', 'yuv420p', new PublicId(), 1),
            new CompletedVideoArtifact('/accepted', 'mp4', 'h264', 'aac', 1920, 1080, 2, 3, 8, 8, false, 'hls', 'yuv420p', new PublicId(), 1),
        ]);
        $service = $this->service(videos: $videos, artifacts: $artifacts);
        $result = $service->video(new Uuid(), new PublicId(), new DeliveryDestination(['mp4'], ['h264'], ['aac'], transports: ['hls']));
        self::assertSame(DeliveryKind::Cached, $result->kind);
        self::assertSame('/accepted', $result->url);
    }

    private function service(?PlaybackMediaAccessInterface $access = null, ?VideoPortInterface $videos = null, ?AudioRenditionPortInterface $audio = null, bool $enabled = true, string $profile = 'household', ?CompletedVideoArtifactPort $artifacts = null, ?TrackStreamMetadata $metadata = null, ?CopyPresentationPortInterface $copies = null): DeliveryDecisionService
    {
        $scopes = $this->createStub(MediaReadScopeProviderInterface::class);
        $scopes->method('forUser')->willReturn(MediaReadScope::authenticated(new Uuid(), LibraryReadScope::unrestricted()));
        $streams = $this->createStub(StreamPortInterface::class);
        $streams->method('getTrackMetadata')->willReturn($metadata ?? new TrackStreamMetadata((new PublicId())->toString(), 'song.flac', '/library/song.flac', 'audio/flac', 100, 'flac', 1000000, 48000, 2, 10.0));
        $streams->method('resolveTrackPath')->willReturn($metadata->filePath ?? '/library/song.flac');
        $settings = $this->createStub(SystemSettingsPortInterface::class);
        $settings->method('get')->willReturnCallback(static fn (string $key): mixed => $key === TranscodeSettingDefinitions::ENABLED ? $enabled : 192);
        return new DeliveryDecisionService($access ?? $this->createStub(PlaybackMediaAccessInterface::class), $scopes,
            $videos ?? $this->createStub(VideoPortInterface::class), $streams,
            new DeliveryCompatibility(new MediaExecutionPolicy(DeploymentProfile::resolve($profile))),
            new MediaExecutionPolicy(DeploymentProfile::resolve($profile)),
            $audio ?? $this->createStub(AudioRenditionPortInterface::class), $settings, $artifacts, $copies);
    }
}
