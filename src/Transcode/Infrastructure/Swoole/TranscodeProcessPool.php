<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Typed dispatch facade for the CPU process pool, specific to transcoding.
 *
 * Wraps the generic CpuProcessPool with domain-specific methods (encodeSegment,
 * encodeInitSegment, analyzeLoudness) that build the correct payload structure
 * and result keys. The encoding loop in TranscodeSessionSubscriber calls these
 * methods to dispatch FFmpeg work and poll for results via the shared result table.
 */
final class TranscodeProcessPool
{
    public function __construct(
        private readonly CpuProcessPool $pool,
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
        private readonly EncoderProfile $encoderProfile,
    ) {
    }

    public function shutdown(): void
    {
        $this->pool->shutdown();
    }

    public function isRunning(): bool
    {
        return $this->pool->isRunning();
    }

    public function encodeInitSegment(
        TranscodeJob $job,
        string $sourcePath,
        QualityTier $tier,
        string $outputPath,
    ): void {
        $profile = $this->encoderProfile->withDecoderForSource('');
        $key = CpuProcessPool::resultKey('encode_init_segment', $job->getId()->toString());
        $payload = $this->jsonEncoder->encode([
            'type' => 'encode_init_segment',
            'source_path' => $sourcePath,
            'output_path' => $outputPath,
            'video_bitrate' => $tier->videoBitrate,
            'max_bitrate' => $tier->maxBitrate,
            'buffer_size' => $tier->bufferSize,
            'encoder_config' => $this->encoderProfile->encoder,
            'hwaccel_flags' => $profile->hwaccelInputFlags(),
            'decoder_flags' => $profile->decoderFlags(),
        ], 'json');

        $this->logger->debug('Dispatching init segment to pool', [
            'jobId' => $job->getId()->toString(),
            'tier' => $tier->name,
        ]);

        $this->pool->dispatch($payload, $key);
    }

    public function encodeSegment(
        TranscodeJob $job,
        int $segmentIndex,
        string $sourcePath,
        float $startTime,
        QualityTier $tier,
        array $audioProfile,
        string $videoFilters,
        string $audioFilters,
        string $outputPath,
    ): void {
        $key = CpuProcessPool::resultKey('encode_segment', $job->getId()->toString(), $segmentIndex);
        $payload = $this->jsonEncoder->encode([
            'type' => 'encode_segment',
            'source_path' => $sourcePath,
            'start_time' => $startTime,
            'duration' => SegmentEncoder::getSegmentDuration(),
            'output_path' => $outputPath,
            'video_bitrate' => $tier->videoBitrate,
            'max_bitrate' => $tier->maxBitrate,
            'buffer_size' => $tier->bufferSize,
            'video_filters' => $videoFilters,
            'audio_filters' => $audioFilters,
            'segment_index' => $segmentIndex,
            'job_id' => $job->getId()->toString(),
            'public_id' => $job->getPublicId()->toString(),
            'encoder_config' => $this->encoderProfile->encoder,
            'source_codec' => '',
            'hwaccel_flags' => $this->encoderProfile->hwaccelInputFlags(),
            'decoder_flags' => $this->encoderProfile->decoderFlags(),
        ], 'json');

        $this->logger->debug('Dispatching segment to pool', [
            'jobId' => $job->getId()->toString(),
            'segmentIndex' => $segmentIndex,
        ]);

        $this->pool->dispatch($payload, $key);
    }

    public function analyzeLoudness(string $sourcePath, string $loudnessFilter, string $jobId): void
    {
        $key = CpuProcessPool::resultKey('analyze_loudness', $jobId);
        $payload = $this->jsonEncoder->encode([
            'type' => 'analyze_loudness',
            'source_path' => $sourcePath,
            'loudness_filter' => $loudnessFilter,
        ], 'json');

        $this->pool->dispatch($payload, $key);
    }

    public function encodeAudioInitSegment(
        TranscodeJob $job,
        string $sourcePath,
        string $language,
        array $audioProfile,
        string $outputPath,
    ): void {
        // Using manual sprintf because CpuProcessPool::resultKey() only supports an optional int suffix,
        // not a string language identifier needed for multi-language dispatch
        $key = sprintf('encode_audio_init_segment:%s:%s', $job->getId()->toString(), $language);
        $payload = $this->jsonEncoder->encode([
            'type' => 'encode_audio_init_segment',
            'source_path' => $sourcePath,
            'output_path' => $outputPath,
            'audio_profile_name' => $audioProfile['name'] ?? 'streaming_stereo',
            'audio_bitrate' => (int) ($audioProfile['bitrate'] ?? 128000),
            'sample_rate' => (int) ($audioProfile['sampleRate'] ?? 48000),
            'channels' => (int) ($audioProfile['channelCount'] ?? 2),
            'language' => $language,
        ], 'json');

        $this->logger->debug('Dispatching audio init segment to pool', [
            'jobId' => $job->getId()->toString(),
            'language' => $language,
        ]);

        $this->pool->dispatch($payload, $key);
    }

    public function encodeAudioSegment(
        TranscodeJob $job,
        int $segmentIndex,
        string $sourcePath,
        float $startTime,
        float $duration,
        array $audioProfile,
        string $audioFilters,
        string $language,
        string $outputPath,
    ): void {
        // Using manual sprintf because CpuProcessPool::resultKey() only supports an optional int suffix,
        // not a string language identifier needed for multi-language dispatch
        $key = sprintf('encode_audio_segment:%s:%s:%d', $job->getId()->toString(), $language, $segmentIndex);
        $payload = $this->jsonEncoder->encode([
            'type' => 'encode_audio_segment',
            'source_path' => $sourcePath,
            'start_time' => $startTime,
            'duration' => $duration,
            'output_path' => $outputPath,
            'audio_profile_name' => $audioProfile['name'] ?? 'streaming_stereo',
            'audio_bitrate' => (int) ($audioProfile['bitrate'] ?? 128000),
            'sample_rate' => (int) ($audioProfile['sampleRate'] ?? 48000),
            'channels' => (int) ($audioProfile['channelCount'] ?? 2),
            'audio_filters' => $audioFilters,
            'language' => $language,
            'segment_index' => $segmentIndex,
            'job_id' => $job->getId()->toString(),
        ], 'json');

        $this->logger->debug('Dispatching audio segment to pool', [
            'jobId' => $job->getId()->toString(),
            'segmentIndex' => $segmentIndex,
            'language' => $language,
        ]);

        $this->pool->dispatch($payload, $key);
    }

    public function extractSubtitles(
        TranscodeJob $job,
        string $sourcePath,
        string $language,
        string $outputPath,
    ): void {
        // Using manual sprintf because CpuProcessPool::resultKey() only supports an optional int suffix,
        // not a string language identifier needed for multi-language dispatch
        $key = sprintf('extract_subtitles:%s:%s', $job->getId()->toString(), $language);
        $payload = $this->jsonEncoder->encode([
            'type' => 'extract_subtitles',
            'source_path' => $sourcePath,
            'output_path' => $outputPath,
            'language' => $language,
        ], 'json');

        $this->logger->debug('Dispatching subtitle extraction to pool', [
            'jobId' => $job->getId()->toString(),
            'language' => $language,
        ]);

        $this->pool->dispatch($payload, $key);
    }

    public function getWorkerCount(): int
    {
        return $this->pool->getWorkerCount();
    }

    public function getResultTable(): ?\Swoole\Table
    {
        return $this->pool->getResultTable();
    }
}
