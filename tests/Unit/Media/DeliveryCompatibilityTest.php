<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media;

use App\Media\Application\DTO\DeliveryDestination;
use App\Media\Application\DTO\DeliveryKind;
use App\Media\Application\DTO\DeliverySource;
use App\Media\Application\Service\DeliveryCompatibility;
use App\Shared\Application\DTO\DeploymentProfile;
use App\Transcode\Application\Service\MediaExecutionPolicy;
use PHPUnit\Framework\TestCase;

final class DeliveryCompatibilityTest extends TestCase
{
    public function testOriginalRequiresActualSourceCodecsAndContainer(): void
    {
        $decision = $this->service()->decide($this->source(), new DeliveryDestination(['mp4'], ['h264'], ['aac']));
        self::assertSame(DeliveryKind::Original, $decision->kind);
    }

    public function testContainerMismatchSelectsCopyWithAbsoluteIndices(): void
    {
        $decision = $this->service()->decide($this->source('matroska'), new DeliveryDestination(['mp4'], ['h264'], ['aac'], transports: ['hls']));
        self::assertSame(DeliveryKind::Copy, $decision->kind);
        self::assertNotNull($decision->selection);
        self::assertSame(4, $decision->selection->videoStreamIndex);
        self::assertSame(7, $decision->selection->audioStreamIndex);
        self::assertNull($decision->url);
    }

    public function testReceiverClaimsCannotPermitAnUnsupportedCodec(): void
    {
        $decision = $this->service()->decide($this->source(), new DeliveryDestination(['mp4'], ['hevc'], ['aac']));
        self::assertSame(DeliveryKind::Incompatible, $decision->kind);
        self::assertSame('video_codec_unsupported', $decision->reason);
    }

    public function testBurnInAndChannelReductionRequireEncoding(): void
    {
        $source = $this->source();
        self::assertSame('subtitle_burn_in_required', $this->service()->decide($source, new DeliveryDestination(['mp4'], ['h264'], ['aac'], subtitleBurnIn: true))->reason);
        self::assertSame('audio_channels_unsupported', $this->service()->decide($source, new DeliveryDestination(['mp4'], ['h264'], ['aac'], maxAudioChannels: 1))->reason);
        self::assertSame(DeliveryKind::Encoded, $this->service('full')->decide($source, new DeliveryDestination(['mp4'], ['hevc'], ['aac']))->kind);
    }

    public function testUntrustedStreamIndexCannotBecomeCopySelection(): void
    {
        $source = new DeliverySource('matroska', 'h264', null, 'aac', 7, 2, 1920, 1080, bitDepth: 8, hdr: false, pixelFormat: 'yuv420p');
        self::assertSame('source_stream_metadata_unavailable', $this->service()->decide($source, new DeliveryDestination(['mp4'], ['h264'], ['aac']))->reason);
    }

    public function testFileContainerSupportDoesNotImplyAdaptiveSupport(): void
    {
        $decision = $this->service()->decide($this->source('matroska'), new DeliveryDestination(['mp4'], ['h264'], ['aac']));
        self::assertSame(DeliveryKind::Incompatible, $decision->kind);
        self::assertSame('container_unsupported', $decision->reason);
    }

    public function testHighBitDepthAndHdrRequireDeclaredReceiverSupport(): void
    {
        $source = new DeliverySource('mp4', 'hevc', 0, 'aac', 1, 2, 1920, 1080, bitDepth: 10, hdr: true, pixelFormat: 'yuv420p10le');
        self::assertSame('video_bit_depth_unsupported', $this->service()->decide($source, new DeliveryDestination(['mp4'], ['hevc'], ['aac'], pixelFormats: ['yuv420p10le']))->reason);
        self::assertSame('video_hdr_unsupported', $this->service()->decide($source, new DeliveryDestination(['mp4'], ['hevc'], ['aac'], maxBitDepth: 10, pixelFormats: ['yuv420p10le']))->reason);
        self::assertSame(DeliveryKind::Original, $this->service()->decide($source, new DeliveryDestination(['mp4'], ['hevc'], ['aac'], maxBitDepth: 10, supportsHdr: true, pixelFormats: ['yuv420p10le']))->kind);
    }

    public function testZeroAudioSlotsRefuseAudioCopyWithTheCompatibilityExplanation(): void
    {
        $service = new DeliveryCompatibility(new MediaExecutionPolicy(new DeploymentProfile('full', 2, 0)));
        $audio = new DeliverySource('matroska', null, null, 'aac', 1, 2, video: false);

        $refused = $service->decide($audio, new DeliveryDestination(['mp4'], [], ['aac'], transports: ['file']));
        self::assertSame(DeliveryKind::Incompatible, $refused->kind);
        self::assertSame('container_unsupported', $refused->reason);
        self::assertSame(DeliveryKind::Copy, $service->decide($this->source('matroska'), new DeliveryDestination(['mp4'], ['h264'], ['aac'], transports: ['hls']))->kind);
    }

    public function testZeroVideoSlotsRefuseVideoCopyWhileAudioCopyContinues(): void
    {
        $service = new DeliveryCompatibility(new MediaExecutionPolicy(new DeploymentProfile('full', 0, 1)));

        $refused = $service->decide($this->source('matroska'), new DeliveryDestination(['mp4'], ['h264'], ['aac'], transports: ['hls']));
        self::assertSame(DeliveryKind::Incompatible, $refused->kind);
        self::assertSame('container_unsupported', $refused->reason);
        $audio = new DeliverySource('matroska', null, null, 'aac', 1, 2, video: false);
        self::assertSame(DeliveryKind::Copy, $service->decide($audio, new DeliveryDestination(['mp4'], [], ['aac'], transports: ['file']))->kind);
    }

    public function testHouseholdSharedCopyPoolKeepsBothCopies(): void
    {
        $audio = new DeliverySource('matroska', null, null, 'aac', 1, 2, video: false);

        self::assertSame(DeliveryKind::Copy, $this->service()->decide($audio, new DeliveryDestination(['mp4'], [], ['aac'], transports: ['file']))->kind);
        self::assertSame(DeliveryKind::Copy, $this->service()->decide($this->source('matroska'), new DeliveryDestination(['mp4'], ['h264'], ['aac'], transports: ['hls']))->kind);
    }

    private function service(string $profile = 'household'): DeliveryCompatibility
    {
        return new DeliveryCompatibility(new MediaExecutionPolicy(DeploymentProfile::resolve($profile)));
    }

    private function source(string $container = 'mp4'): DeliverySource
    {
        return new DeliverySource($container, 'h264', 4, 'aac', 7, 2, 1920, 1080, bitDepth: 8, hdr: false, pixelFormat: 'yuv420p');
    }
}
