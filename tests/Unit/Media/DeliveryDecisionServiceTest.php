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
use App\Media\Application\Port\VideoQualityChoicesPortInterface;
use App\Media\Application\Service\DeliveryCompatibility;
use App\Media\Application\Service\DeliveryDecisionService;
use App\Media\Interface\Resource\DeliveryResource;
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
use PHPUnit\Framework\Attributes\DataProvider;

final class DeliveryDecisionServiceTest extends TestCase
{
    public function testDashOnlyCopyReturnsTheSelectedManifest(): void
    {
        $videos = $this->createStub(VideoPortInterface::class);
        $videos->method('getSourceMetadata')->willReturn(new VideoSourceMetadata(
            new Uuid(),
            new PublicId()->toString(),
            '/library/movie.mkv',
            'movie.mkv',
            100,
            'video/x-matroska',
            'hash',
            'matroska',
            'h264',
            3,
            [['codec' => 'aac', 'channels' => 2, 'streamIndex' => 8, 'isDefault' => true]],
            1920,
            1080,
            24.0,
            100.0,
            pixFmt: 'yuv420p',
            colorSpace: ['transfer' => 'bt709'],
        ));
        $requestId = new Uuid()->toString();
        $presentation = new CopyPresentation(new PublicId(), new PublicId(), $requestId);
        $copies = $this->createMock(CopyPresentationPortInterface::class);
        $copies->expects(self::once())->method('start')->willReturn($presentation);
        $videoId = new PublicId();
        $decision = $this->service(videos: $videos, copies: $copies)->video(
            new Uuid(),
            $videoId,
            new DeliveryDestination(['mp4'], ['h264'], ['aac'], transports: ['dash']),
            $requestId,
        );

        self::assertSame(DeliveryKind::Copy, $decision->kind);
        self::assertSame('dash', $decision->selection?->transport);
        self::assertSame('/api/transcode/' . $videoId->toString() . '/manifest.mpd', $decision->url);
        self::assertSame($requestId, $decision->requestId);
        self::assertSame($presentation->jobId->toString(), $decision->jobId);
        self::assertSame($presentation->sessionId->toString(), $decision->sessionId);
        self::assertSame(
            $presentation->sessionId->toString(),
            DeliveryResource::from($decision->withUrl('/signed')->withRequestId($requestId))['sessionId'],
        );
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function originalContainers(): iterable
    {
        yield 'mp4' => ['movie.mp4', 'mov,mp4,m4a,3gp,3g2,mj2', 'mp4'];
        yield 'mov' => ['movie.mov', 'mov,mp4,m4a,3gp,3g2,mj2', 'mov'];
        yield 'matroska' => ['movie.mkv', 'matroska,webm', 'matroska'];
        yield 'webm' => ['movie.webm', 'matroska,webm', 'webm'];
    }

    #[DataProvider('originalContainers')]
    public function testTheContainerIsNamedFromTheProbeAndTheExtensionNotTheServedType(string $filename, string $format, string $container): void
    {
        $decision = $this->service(videos: $this->video($filename, $format))->video(
            new Uuid(),
            new PublicId(),
            new DeliveryDestination([$container], ['h264'], ['aac']),
        );

        self::assertSame(DeliveryKind::Original, $decision->kind);
    }

    public function testAFormatNoVideoContainerIsReadByIsNeverOfferedAsTheOriginal(): void
    {
        $decision = $this->service(videos: $this->video('Holiday Clip (2021).mkv', 'mpegts'))->video(
            new Uuid(),
            new PublicId(),
            new DeliveryDestination(['mpegts', 'matroska'], ['h264'], ['aac']),
        );

        self::assertNotSame(DeliveryKind::Original, $decision->kind);
        self::assertSame('container_unsupported', $decision->reason);
    }

    public function testATrackWithoutAStoredContainerFormatIsNamedByItsExtension(): void
    {
        $metadata = new TrackStreamMetadata('track', 'song.mp3', '/library/song.mp3', 'application/octet-stream', 100, 'mp3', 128000, 48000, 2, 10.0);

        $decision = $this->service(metadata: $metadata)->track(
            new Uuid(),
            new PublicId(),
            new DeliveryDestination(['mp3'], audioCodecs: ['mp3']),
        );

        self::assertSame(DeliveryKind::Original, $decision->kind);
    }

    public function testFailedNativeSourceCanOnlyFallBackToPermittedEncoding(): void
    {
        $metadata = new TrackStreamMetadata(
            'track',
            'song.mp3',
            '/library/song.mp3',
            'audio/mpeg',
            100,
            'mp3',
            128000,
            48000,
            2,
            10.0,
            'mp3',
            0,
            '0123456789abcdef',
        );
        $destination = new DeliveryDestination(['mp3'], audioCodecs: ['mp3'], sourcePlaybackFailed: true);
        $full = $this->service(profile: new DeploymentProfile(), metadata: $metadata)->track(
            new Uuid(),
            new PublicId(),
            $destination,
        );
        self::assertSame(DeliveryKind::Encoded, $full->kind);
        self::assertSame('browser_rejected_source', $full->reason);
        $remux = $this->service(metadata: $metadata)->track(new Uuid(), new PublicId(), $destination);
        self::assertSame(DeliveryKind::Incompatible, $remux->kind);
        self::assertSame('browser_rejected_source', $remux->reason);
        $disabled = $this->service(enabled: false, profile: new DeploymentProfile(), metadata: $metadata)->track(
            new Uuid(),
            new PublicId(),
            $destination,
        );
        self::assertSame(DeliveryKind::Incompatible, $disabled->kind);
        self::assertSame('audio_transcoding_disabled', $disabled->reason);
    }

    public function testEmbeddedSubtitlesKeepTheirAdaptiveRenditions(): void
    {
        $videos = $this->createStub(VideoPortInterface::class);
        $videos->method('getSourceMetadata')->willReturn(new VideoSourceMetadata(
            new Uuid(),
            new PublicId()->toString(),
            '/library/movie.mp4',
            'movie.mp4',
            100,
            'video/mp4',
            'hash',
            'mp4',
            'h264',
            0,
            [['codec' => 'aac', 'channels' => 2, 'streamIndex' => 1, 'isDefault' => true]],
            1920,
            1080,
            24.0,
            100.0,
            pixFmt: 'yuv420p',
            colorSpace: ['transfer' => 'bt709'],
            hasSubtitles: true,
        ));
        $service = $this->service(videos: $videos);
        $decision = $service->video(
            new Uuid(),
            new PublicId(),
            new DeliveryDestination(
                ['mp4'],
                ['h264'],
                ['aac'],
                transports: [
                    'file',
                    'hls',
                ],
            ),
        );
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
        $this->service($access, $videos)->video(
            new Uuid(),
            new PublicId(),
            new DeliveryDestination(
                ['mp4'],
                ['h264'],
                ['aac'],
            ),
        );
    }

    public function testSelectedAbsoluteStreamIndexMustExist(): void
    {
        $videos = $this->createStub(VideoPortInterface::class);
        $videos->method('getSourceMetadata')->willReturn(new VideoSourceMetadata(
            new Uuid(),
            new PublicId()->toString(),
            '/library/movie.mkv',
            'movie.mkv',
            100,
            'video/x-matroska',
            'hash',
            'matroska',
            'h264',
            3,
            [['codec' => 'aac', 'channels' => 2, 'streamIndex' => 8, 'isDefault' => true]],
            1920,
            1080,
            24.0,
            100.0,
            pixFmt: 'yuv420p',
            colorSpace: ['primaries' => 'bt709', 'transfer' => 'bt709', 'matrix' => 'bt709'],
        ));
        $service = $this->service($this->createStub(PlaybackMediaAccessInterface::class), $videos);
        self::assertSame(
            'audio_stream_unavailable',
            $service->video(
                new Uuid(),
                new PublicId(),
                new DeliveryDestination(
                    ['mp4'],
                    ['h264'],
                    ['aac'],
                    audioStreamIndex: 0,
                ),
            )->reason,
        );
        $copy = $service->video(
            new Uuid(),
            new PublicId(),
            new DeliveryDestination(
                ['mp4'],
                ['h264'],
                ['aac'],
                audioStreamIndex: 8,
                transports: ['hls'],
            ),
        );
        self::assertSame(DeliveryKind::Copy, $copy->kind);
        self::assertSame(8, $copy->selection?->audioStreamIndex);
        self::assertNull($copy->url);
    }

    public function testAlternateAudioCannotUseTheUnchangedOriginal(): void
    {
        $videos = $this->createStub(VideoPortInterface::class);
        $videos->method('getSourceMetadata')->willReturn(new VideoSourceMetadata(
            new Uuid(),
            new PublicId()->toString(),
            '/library/movie.mp4',
            'movie.mp4',
            100,
            'video/mp4',
            'hash',
            'mov,mp4,m4a,3gp,3g2,mj2',
            'h264',
            3,
            [['codec' => 'aac', 'channels' => 2, 'streamIndex' => 8, 'isDefault' => true],
                ['codec' => 'aac', 'channels' => 2, 'streamIndex' => 11, 'isDefault' => false]],
            1920,
            1080,
            24.0,
            100.0,
            pixFmt: 'yuv420p',
            colorSpace: ['transfer' => 'bt709'],
        ));
        $service = $this->service(videos: $videos);
        $default = $service->video(
            new Uuid(),
            new PublicId(),
            new DeliveryDestination(
                ['mp4'],
                ['h264'],
                ['aac'],
                audioStreamIndex: 8,
            ),
        );
        self::assertSame(DeliveryKind::Original, $default->kind);
        $alternate = $service->video(
            new Uuid(),
            new PublicId(),
            new DeliveryDestination(
                ['mp4'],
                ['h264'],
                ['aac'],
                audioStreamIndex: 11,
                transports: [
                    'file',
                    'hls',
                ],
            ),
        );
        self::assertSame(DeliveryKind::Copy, $alternate->kind);
        self::assertSame(11, $alternate->selection?->audioStreamIndex);
        self::assertNull($alternate->url);
        $unsupported = $service->video(
            new Uuid(),
            new PublicId(),
            new DeliveryDestination(
                ['mp4'],
                ['h264'],
                ['aac'],
                audioStreamIndex: 11,
            ),
        );
        self::assertSame(DeliveryKind::Incompatible, $unsupported->kind);
        self::assertSame('container_unsupported', $unsupported->reason);
        $full = $this->service(videos: $videos, profile: new DeploymentProfile())->video(
            new Uuid(),
            new PublicId(),
            new DeliveryDestination(
                ['mp4'],
                ['h264'],
                ['aac'],
                audioStreamIndex: 11,
            ),
        );
        self::assertSame(DeliveryKind::Encoded, $full->kind);
    }

    public function testAudioCorrectionRequiresEncodingAndBypassesUncorrectedArtifacts(): void
    {
        $videos = $this->createStub(VideoPortInterface::class);
        $videos->method('getSourceMetadata')->willReturn(new VideoSourceMetadata(
            new Uuid(),
            new PublicId()->toString(),
            '/library/movie.mp4',
            'movie.mp4',
            100,
            'video/mp4',
            'hash',
            'mp4',
            'h264',
            0,
            [[
                'codec' => 'aac',
                'channels' => 2,
                'streamIndex' => 2,
                'isDefault' => true,
            ]],
            1920,
            1080,
            24.0,
            100.0,
            pixFmt: 'yuv420p',
            colorSpace: ['transfer' => 'bt709'],
        ));
        foreach ([-10000, 750, 10000] as $offset) {
            $destination = new DeliveryDestination(['mp4'], ['h264'], ['aac'], audioOffsetMs: $offset);
            $decision = $this->service(videos: $videos, profile: new DeploymentProfile())->video(
                new Uuid(),
                new PublicId(),
                $destination,
            );
            self::assertSame(DeliveryKind::Encoded, $decision->kind);
            self::assertSame('audio_timing_correction_required', $decision->reason);
            self::assertNull($decision->artifact);
            self::assertSame(
                DeliveryKind::Incompatible,
                $this->service(videos: $videos)->video(
                    new Uuid(),
                    new PublicId(),
                    $destination,
                )->kind,
            );
        }
    }

    public function testCompatibleCachedAudioIsReadWithoutAdmission(): void
    {
        $audio = $this->createMock(AudioRenditionPortInterface::class);
        $audio->expects(self::never())->method('open');
        $audio->expects(self::once())->method('findComplete')->willReturn(AudioRendition::complete(
            AudioRenditionFormat::Mp3,
            '/cache/audio.mp3',
        ));
        $decision = $this->service(audio: $audio)->track(
            new Uuid(),
            new PublicId(),
            new DeliveryDestination(
                ['mp3'],
                audioCodecs: ['mp3'],
            ),
        );
        self::assertSame(DeliveryKind::Cached, $decision->kind);
        self::assertStringContainsString('format=mp3', (string) $decision->url);
    }

    public function testRemuxCanReadCompatibleCompleteAudioWithEncodingToggleOff(): void
    {
        $audio = $this->createMock(AudioRenditionPortInterface::class);
        $audio->expects(self::never())->method('open');
        $audio->expects(self::once())->method('findComplete')->willReturn(AudioRendition::complete(
            AudioRenditionFormat::Mp3,
            '/cache/audio.mp3',
        ));
        $result = $this->service(audio: $audio, enabled: false)->track(
            new Uuid(),
            new PublicId(),
            new DeliveryDestination(
                ['mp3'],
                audioCodecs: ['mp3'],
            ),
        );
        self::assertSame(DeliveryKind::Cached, $result->kind);
    }

    public function testScannedAacSelectsOwnedMp4CopyWithItsAbsoluteStream(): void
    {
        $metadata = new TrackStreamMetadata(
            'track',
            'song.aac',
            '/library/song.aac',
            'audio/aac',
            100,
            'aac',
            128000,
            48000,
            2,
            10.0,
            'aac',
            3,
            '0123456789abcdef',
        );
        $audio = $this->createMock(AudioRenditionPortInterface::class);
        $audio->expects(self::never())->method('open');
        $audio->expects(self::never())->method('openCopy');
        $audio->expects(self::once())->method('findCompleteCopy')->with(
            self::anything(),
            '/library/song.aac',
            '0123456789abcdef',
            3,
        )->willReturn(null);
        $decision = $this->service(audio: $audio, enabled: false, metadata: $metadata)->track(
            new Uuid(),
            new PublicId(),
            new DeliveryDestination(
                ['mp4'],
                audioCodecs: ['aac'],
            ),
        );
        self::assertSame(DeliveryKind::Copy, $decision->kind);
        self::assertStringContainsString('mode=copy', (string) $decision->url);
        self::assertStringContainsString('index=3', (string) $decision->url);
    }

    public function testAudioToggleSkipsCacheAndReturnsTerminalReason(): void
    {
        $audio = $this->createMock(AudioRenditionPortInterface::class);
        $audio->expects(self::never())->method('findComplete');
        $decision = $this->service(audio: $audio, enabled: false, profile: new DeploymentProfile())->track(
            new Uuid(),
            new PublicId(),
            new DeliveryDestination(
                ['mp3'],
                audioCodecs: ['mp3'],
            ),
        );
        self::assertSame(DeliveryKind::Incompatible, $decision->kind);
        self::assertSame('audio_transcoding_disabled', $decision->reason);
    }

    public function testCompleteVideoArtifactMustMatchSelectedStreamsAndDestination(): void
    {
        $videos = $this->createStub(VideoPortInterface::class);
        $videos->method('getSourceMetadata')->willReturn(new VideoSourceMetadata(
            new Uuid(),
            new PublicId()->toString(),
            '/library/movie.mkv',
            'movie.mkv',
            100,
            'video/x-matroska',
            'hash',
            'matroska',
            'h264',
            3,
            [['codec' => 'aac', 'channels' => 2, 'streamIndex' => 8, 'isDefault' => true]],
            1920,
            1080,
            24.0,
            100.0,
            pixFmt: 'yuv420p',
            colorSpace: ['transfer' => 'bt709'],
        ));
        $artifacts = $this->createStub(CompletedVideoArtifactPort::class);
        $artifacts->method('findComplete')->willReturn([
            new CompletedVideoArtifact(
                '/wrong-stream',
                'mp4',
                'h264',
                'aac',
                1920,
                1080,
                2,
                3,
                1,
                8,
                false,
                'hls',
                'yuv420p',
                new PublicId(),
                1,
            ),
            new CompletedVideoArtifact(
                '/wrong-bit-depth',
                'mp4',
                'h264',
                'aac',
                1920,
                1080,
                2,
                3,
                8,
                10,
                false,
                'hls',
                'yuv420p',
                new PublicId(),
                1,
            ),
            new CompletedVideoArtifact(
                '/accepted',
                'mp4',
                'h264',
                'aac',
                1920,
                1080,
                2,
                3,
                8,
                8,
                false,
                'hls',
                'yuv420p',
                new PublicId(),
                1,
            ),
        ]);
        $service = $this->service(videos: $videos, artifacts: $artifacts);
        $result = $service->video(
            new Uuid(),
            new PublicId(),
            new DeliveryDestination(
                ['mp4'],
                ['h264'],
                ['aac'],
                transports: ['hls'],
            ),
        );
        self::assertSame(DeliveryKind::Cached, $result->kind);
        self::assertSame('/accepted', $result->url);
    }

    #[DataProvider('manualPresentations')]
    public function testManualQualityReusesOnlyTheExactCompletedTierWithoutProducerSlots(
        int $audioOffsetMs,
        bool $sourcePlaybackFailed,
        bool $qualityAllowed,
        bool $sourceCopy,
        int $sourceWidth = 1920,
    ): void {
        $videos = $this->createStub(VideoPortInterface::class);
        $videos->method('getSourceMetadata')->willReturn(new VideoSourceMetadata(
            new Uuid(),
            new PublicId()->toString(),
            '/library/movie.mkv',
            'movie.mkv',
            100,
            'video/x-matroska',
            'hash',
            'matroska',
            'h264',
            3,
            [['codec' => 'aac', 'channels' => 2, 'streamIndex' => 8, 'isDefault' => true]],
            $sourceWidth,
            1080,
            24.0,
            100.0,
            pixFmt: 'yuv420p',
            colorSpace: ['transfer' => 'bt709'],
        ));
        $artifacts = $this->createStub(CompletedVideoArtifactPort::class);
        $artifacts->method('findComplete')->willReturn([
            new CompletedVideoArtifact(
                '/wrong-tier',
                'mp4',
                'h264',
                'aac',
                1280,
                720,
                2,
                3,
                8,
                8,
                false,
                'hls',
                'yuv420p',
                new PublicId(),
                1,
                $sourceCopy ? 'copy' : '1080p',
                $audioOffsetMs,
                $sourceCopy,
            ),
            new CompletedVideoArtifact(
                '/wrong-bit-depth',
                'mp4',
                'h264',
                'aac',
                $sourceWidth,
                1080,
                2,
                3,
                8,
                10,
                false,
                'hls',
                'yuv420p',
                new PublicId(),
                1,
                $sourceCopy ? 'copy' : '1080p',
                $audioOffsetMs,
                $sourceCopy,
            ),
            new CompletedVideoArtifact(
                '/accepted',
                'mp4',
                'h264',
                'aac',
                $sourceWidth,
                1080,
                2,
                3,
                8,
                8,
                false,
                'hls',
                'yuv420p',
                new PublicId(),
                1,
                $sourceCopy ? 'copy' : '1080p',
                $audioOffsetMs,
                $sourceCopy,
            ),
        ]);
        $service = $this->service(
            videos: $videos,
            profile: new DeploymentProfile(videoEncoderSlots: 0),
            artifacts: $artifacts,
            qualityAllowed: $qualityAllowed,
        );
        $result = $service->video(
            new Uuid(),
            new PublicId(),
            new DeliveryDestination(
                ['mp4'],
                ['h264'],
                ['aac'],
                transports: ['hls'],
                sourcePlaybackFailed: $sourcePlaybackFailed,
                audioOffsetMs: $audioOffsetMs,
                qualityTier: '1080p',
            ),
        );
        if (!$qualityAllowed) {
            self::assertSame(DeliveryKind::Incompatible, $result->kind);
            self::assertSame('requested_quality_unavailable', $result->reason);
            return;
        }
        self::assertSame(DeliveryKind::Cached, $result->kind);
        self::assertSame('/accepted', $result->url);
    }

    /** @return iterable<string, array{int, bool, bool, bool, int}> */
    public static function manualPresentations(): iterable
    {
        yield 'uncorrected source' => [0, false, true, false, 1920];
        yield 'corrected audio after source failure' => [-750, true, true, false, 1920];
        yield 'policy denied cached tier' => [0, false, false, false, 1920];
        yield 'completed source copy without a producer' => [0, false, true, true, 1920];
        yield 'source copy keeps source aspect dimensions' => [0, false, true, true, 2048];
    }

    /** A source whose served type is unknown, so only its probe and extension can name the container. */
    private function video(string $filename, string $format): VideoPortInterface
    {
        $videos = $this->createStub(VideoPortInterface::class);
        $videos->method('getSourceMetadata')->willReturn(new VideoSourceMetadata(
            new Uuid(),
            new PublicId()->toString(),
            '/library/' . $filename,
            $filename,
            100,
            'application/octet-stream',
            'hash',
            $format,
            'h264',
            0,
            [['codec' => 'aac', 'channels' => 2, 'streamIndex' => 1, 'isDefault' => true]],
            1920,
            1080,
            24.0,
            100.0,
            pixFmt: 'yuv420p',
            colorSpace: ['transfer' => 'bt709'],
        ));

        return $videos;
    }

    private function service(
        ?PlaybackMediaAccessInterface $access = null,
        ?VideoPortInterface $videos = null,
        ?AudioRenditionPortInterface $audio = null,
        bool $enabled = true,
        ?DeploymentProfile $profile = null,
        ?CompletedVideoArtifactPort $artifacts = null,
        ?TrackStreamMetadata $metadata = null,
        ?CopyPresentationPortInterface $copies = null,
        bool $qualityAllowed = true,
    ): DeliveryDecisionService {
        $profile ??= new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2);
        $scopes = $this->createStub(MediaReadScopeProviderInterface::class);
        $scopes->method('forUser')->willReturn(MediaReadScope::authenticated(
            new Uuid(),
            LibraryReadScope::unrestricted(),
        ));
        $streams = $this->createStub(StreamPortInterface::class);
        $streams->method('getTrackMetadata')->willReturn($metadata ?? new TrackStreamMetadata(
            new PublicId()->toString(),
            'song.flac',
            '/library/song.flac',
            'audio/flac',
            100,
            'flac',
            1000000,
            48000,
            2,
            10.0,
        ));
        $streams->method('resolveTrackPath')->willReturn($metadata->filePath ?? '/library/song.flac');
        $settings = $this->createStub(SystemSettingsPortInterface::class);
        $settings->method('get')->willReturnCallback(static fn(string $key): mixed => $key === TranscodeSettingDefinitions::ENABLED ? $enabled : 192);
        $qualityChoices = $this->createStub(VideoQualityChoicesPortInterface::class);
        $qualityChoices->method('choices')->willReturn($qualityAllowed ? [
            ['id' => '1080p', 'label' => '1080p', 'width' => 1920, 'height' => 1080],
        ] : []);
        return new DeliveryDecisionService(
            $access ?? $this->createStub(PlaybackMediaAccessInterface::class),
            $scopes,
            $videos ?? $this->createStub(VideoPortInterface::class),
            $streams,
            new DeliveryCompatibility(new MediaExecutionPolicy($profile)),
            new MediaExecutionPolicy($profile),
            $audio ?? $this->createStub(AudioRenditionPortInterface::class),
            $settings,
            $artifacts ?? $this->createStub(CompletedVideoArtifactPort::class),
            $copies ?? $this->createStub(CopyPresentationPortInterface::class),
            $qualityChoices,
        );
    }
}
