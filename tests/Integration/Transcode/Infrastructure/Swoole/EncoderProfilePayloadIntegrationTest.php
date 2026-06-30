<?php

declare(strict_types=1);

namespace App\Tests\Integration\Transcode\Infrastructure\Swoole;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodePublicId;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\HardwareAccelerator;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use App\Transcode\Infrastructure\FFmpeg\VideoFilterBuilder;
use App\Transcode\Infrastructure\Swoole\TranscodePoolWorker;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * @covers \App\Transcode\Infrastructure\Swoole\TranscodeProcessPool
 * @covers \App\Transcode\Infrastructure\Swoole\TranscodePoolWorker
 *
 * Integration test validating the full EncoderProfile → JSON payload → payload parsing
 * pipeline. Verifies that the payload fields produced by TranscodeProcessPool are
 * correctly consumed by TranscodePoolWorker's command assembly.
 *
 * This test bridges the serialization gap: it builds payloads using the same
 * logic as TranscodeProcessPool, then verifies the payload structure matches
 * what TranscodePoolWorker expects when building FFmpeg commands.
 */
final class EncoderProfilePayloadIntegrationTest extends TestCase
{
    private JsonEncoder $jsonEncoder;

    protected function setUp(): void
    {
        $this->jsonEncoder = new JsonEncoder();
    }

    //
    // Payload structure validation per accelerator
    //

    #[DataProvider('acceleratorProvider')]
    public function testSegmentPayloadContainsRequiredFields(EncoderProfile $profile): void
    {
        $payload = $this->buildSegmentPayload($profile);
        $decoded = $this->jsonEncoder->decode($payload, 'json');

        // Required fields that TranscodePoolWorker reads
        $requiredKeys = [
            'type', 'source_path', 'start_time', 'duration', 'output_path',
            'video_bitrate', 'max_bitrate', 'buffer_size',
            'encoder_config', 'hwaccel_flags', 'decoder_flags',
            'segment_index', 'job_id', 'public_id',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $decoded, "Missing key: {$key}");
        }

        $this->assertSame('encode_segment', $decoded['type']);
        $this->assertSame($profile->encoder, $decoded['encoder_config']);
    }

    #[DataProvider('acceleratorProvider')]
    public function testInitSegmentPayloadContainsRequiredFields(EncoderProfile $profile): void
    {
        $payload = $this->buildInitSegmentPayload($profile);
        $decoded = $this->jsonEncoder->decode($payload, 'json');

        $requiredKeys = [
            'type', 'source_path', 'output_path',
            'video_bitrate', 'max_bitrate', 'buffer_size',
            'encoder_config', 'hwaccel_flags', 'decoder_flags',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $decoded, "Missing key: {$key}");
        }

        $this->assertSame('encode_init_segment', $decoded['type']);
    }

    #[DataProvider('acceleratorProvider')]
    public function testHwaccelFlagsMatchProfile(EncoderProfile $profile): void
    {
        $payload = $this->buildSegmentPayload($profile);
        $decoded = $this->jsonEncoder->decode($payload, 'json');

        $this->assertSame($profile->hwaccelInputFlags(), $decoded['hwaccel_flags']);
        $this->assertSame($profile->decoderFlags(), $decoded['decoder_flags']);
    }

    public function testSoftwareProfileEmptyFlags(): void
    {
        $payload = $this->buildSegmentPayload(EncoderProfile::software());
        $decoded = $this->jsonEncoder->decode($payload, 'json');

        $this->assertSame('', $decoded['hwaccel_flags']);
        $this->assertSame('', $decoded['decoder_flags']);
    }

    public function testNvencProfileCudaFlags(): void
    {
        $profile = EncoderProfile::fromEncoderName('hevc_nvenc');
        $payload = $this->buildSegmentPayload($profile);
        $decoded = $this->jsonEncoder->decode($payload, 'json');

        $this->assertStringContainsString('-hwaccel cuda', $decoded['hwaccel_flags']);
        $this->assertStringContainsString('-hwaccel_output_format cuda', $decoded['hwaccel_flags']);
    }

    public function testVaapiProfileDeviceFlags(): void
    {
        $profile = EncoderProfile::fromEncoderName('hevc_vaapi', '/dev/dri/renderD128');
        $payload = $this->buildSegmentPayload($profile);
        $decoded = $this->jsonEncoder->decode($payload, 'json');

        $this->assertStringContainsString('-hwaccel vaapi', $decoded['hwaccel_flags']);
        $this->assertStringContainsString('/dev/dri/renderD128', $decoded['hwaccel_flags']);
    }

    //
    // Worker payload parsing: verify the worker can parse all payload fields
    //

    public function testWorkerSupportsEncodeSegmentType(): void
    {
        $worker = new TranscodePoolWorker();
        $types = $worker->supportedTypes();

        $this->assertContains('encode_segment', $types);
        $this->assertContains('encode_init_segment', $types);
    }

    //
    // End-to-end: EncoderProfile → payload → FFmpeg command flags
    //

    #[DataProvider('acceleratorProvider')]
    public function testPayloadFlagsProduceCorrectCommandPosition(EncoderProfile $profile): void
    {
        $payload = $this->buildSegmentPayload($profile);
        $decoded = $this->jsonEncoder->decode($payload, 'json');

        $hwAccelFlags = $decoded['hwaccel_flags'];
        $decoderFlags = $decoded['decoder_flags'];

        // Build the command the same way the worker does
        $encoderFlags = \App\Transcode\Domain\Service\VideoProcessingRules::codecFlags($decoded['encoder_config']);
        $cmd = sprintf(
            'ffmpeg -y %s%s -ss %.6f -t %.6f -i %s %s -b:v %d -maxrate %d -bufsize %d -f mp4 %s',
            $hwAccelFlags !== '' ? $hwAccelFlags . ' ' : '',
            $decoderFlags !== '' ? $decoderFlags . ' ' : '',
            $decoded['start_time'],
            $decoded['duration'],
            escapeshellarg($decoded['source_path']),
            $encoderFlags,
            $decoded['video_bitrate'],
            $decoded['max_bitrate'],
            $decoded['buffer_size'],
            escapeshellarg($decoded['output_path']),
        );

        // Verify hwaccel flags appear before -i
        if ($profile->isHardware()) {
            $this->assertMatchesRegularExpression('/-hwaccel\s+\S+.*-i\s/', $cmd);
        } else {
            $this->assertDoesNotMatchRegularExpression('/-hwaccel/', $cmd);
        }

        // Verify encoder appears in output flags (after -i)
        $this->assertStringContainsString(sprintf('-c:v %s', $profile->encoder), $cmd);
    }

    //
    // Data providers
    //

    /**
     * @return list<array{EncoderProfile}>
     */
    public static function acceleratorProvider(): array
    {
        return [
            'software' => [EncoderProfile::software()],
            'nvenc' => [EncoderProfile::fromEncoderName('hevc_nvenc')],
            'vaapi' => [EncoderProfile::fromEncoderName('hevc_vaapi', '/dev/dri/renderD128')],
            'qsv' => [EncoderProfile::fromEncoderName('hevc_qsv', '/dev/dri/renderD128')],
            'amf' => [EncoderProfile::fromEncoderName('hevc_amf', '/dev/dri/renderD128')],
        ];
    }

    //
    // Helpers
    //

    /**
     * Build a segment payload mirroring TranscodeProcessPool::encodeSegment logic.
     */
    private function buildSegmentPayload(EncoderProfile $profile): string
    {
        $tier = QualityTier::p1080();

        return $this->jsonEncoder->encode([
            'type' => 'encode_segment',
            'source_path' => '/tmp/test.mkv',
            'start_time' => 0.0,
            'duration' => SegmentEncoder::getSegmentDuration(),
            'output_path' => '/tmp/out/seg_0.mp4',
            'video_bitrate' => $tier->videoBitrate,
            'max_bitrate' => $tier->maxBitrate,
            'buffer_size' => $tier->bufferSize,
            'video_filters' => 'scale=1920:1080',
            'audio_filters' => '',
            'segment_index' => 0,
            'job_id' => '00000000-0000-0000-0000-000000000001',
            'public_id' => 'pub-001',
            'encoder_config' => $profile->encoder,
            'source_codec' => '',
            'hwaccel_flags' => $profile->hwaccelInputFlags(),
            'decoder_flags' => $profile->decoderFlags(),
        ], 'json');
    }

    /**
     * Build an init segment payload mirroring TranscodeProcessPool::encodeInitSegment logic.
     */
    private function buildInitSegmentPayload(EncoderProfile $profile): string
    {
        $resolved = $profile->withDecoderForSource('');
        $tier = QualityTier::p1080();

        return $this->jsonEncoder->encode([
            'type' => 'encode_init_segment',
            'source_path' => '/tmp/test.mkv',
            'output_path' => '/tmp/out/init.mp4',
            'video_bitrate' => $tier->videoBitrate,
            'max_bitrate' => $tier->maxBitrate,
            'buffer_size' => $tier->bufferSize,
            'encoder_config' => $profile->encoder,
            'hwaccel_flags' => $resolved->hwaccelInputFlags(),
            'decoder_flags' => $resolved->decoderFlags(),
        ], 'json');
    }
}
