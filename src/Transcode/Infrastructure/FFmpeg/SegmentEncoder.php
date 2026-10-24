<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\Service\VideoProcessingRules;
use App\Transcode\Domain\ValueObject\ColorSpace;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\HardwareAccelerator;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\VideoProbeResult;

final class SegmentEncoder
{
    private const float SEGMENT_DURATION = 6.0; // seconds per CMAF segment
    private const string FFMPEG_PATH = '/usr/bin/ffmpeg';

    public function __construct(
        private readonly FFmpegPortInterface $ffmpeg,
        private readonly TranscodeStoragePortInterface $storage,
        private readonly EncoderProfile $encoderProfile = new EncoderProfile(
            HardwareAccelerator::None,
            'libx265',
            '',
            '',
            '',
            '',
        ),
        private readonly float $bitrateMultiplier = 1.0,
    ) {
    }

    /**
     * Encode the init segment for a job.
     */
    public function getEncoderProfile(): EncoderProfile
    {
        return $this->encoderProfile;
    }

    public function encodeInit(TranscodeJob $job, string $sourcePath): string
    {
        $tier = QualityTier::fromString($job->getQualityTierName());
        $initPath = $this->storage->resolveInitSegmentPath($job->getVideoId(), $tier);

        return $this->ffmpeg->encodeInitSegment($sourcePath, $tier, $initPath);
    }

    /**
     * Build video filters based on probe result and quality tier.
     */
    public function buildVideoFilters(VideoProbeResult $probe, QualityTier $tier): string
    {
        $builder = VideoFilterBuilder::create($this->encoderProfile->accelerator);

        if ($probe->isInterlaced) {
            $builder->deinterlace();
        }

        $method = VideoProcessingRules::resolveToneMapMethod($probe, $tier);
        $builder->tonemap($probe, $method, ColorSpace::bt709());
        $builder->scale($tier);

        if ($probe->framerate > 0 && $probe->framerate > 30.0) {
            $builder->framerate(30.0);
        }

        return $builder->build();
    }

    /**
     * Build audio filters based on probe result, session audio profile, and optional measured loudness.
     */
    /**
     * @param array<string, mixed> $measuredLoudness
     */
    public function buildAudioFilters(
        VideoProbeResult $probe,
        TranscodeSession $session,
        array $measuredLoudness = [],
    ): string {
        return $this->buildAudioFiltersForProfile($probe, $session->getAudioProfile(), $measuredLoudness);
    }

    /** @param array<string, mixed> $measuredLoudness */
    public function buildAudioFiltersForProfile(VideoProbeResult $probe, AudioProfile $profile, array $measuredLoudness = []): string
    {
        if (!$probe->hasAudio()) {
            return '';
        }

        $builder = AudioFilterBuilder::create();
        $builder->downmix($probe, $profile);
        $builder->dialogueEnhancement($probe, $profile);
        $builder->loudness($profile->loudnessStandard, $measuredLoudness);
        $builder->drc($profile);
        $builder->channelLayout($profile);
        $builder->resample($probe, $profile);

        return $builder->build();
    }

    /**
     * Build the FFmpeg command for the long-lived video stream.
     *
     * Produces separate fMP4 tracks with a native DASH index and HLS playlists.
     * One process produces all video segments for a tier — no per-segment
     * re-seek or encoder re-init.
     *
     * The native index publishes actual track timing after closed media files
     * are renamed. Initialization files are validated before readiness.
     *
     * @param string $sourcePath Path to the source video file
     * @param QualityTier $tier Target quality tier
     * @param string $videoFilters FFmpeg video filter chain (-vf), may be empty
     * @param string $outputDir Directory for segment output
     * @param ?int $startSegment Starting segment number (for seek support)
     * @param int $videoStreamIndex Ordinal among source video streams excluding attached pictures (default 0)
     * @param int $audioStreamIndex Source audio stream index (default 0)
     * @param bool $hasAudio Whether the source contains an audio stream
     * @return list<string> FFmpeg argument array (bypasses shell entirely via proc_open array form)
     */
    public function buildStreamArgs(
        string $sourcePath,
        QualityTier $tier,
        string $videoFilters,
        string $outputDir,
        ?int $startSegment = null,
        int $videoStreamIndex = 0,
        int $audioStreamIndex = 0,
        ?AudioProfile $audioProfile = null,
        string $audioFilters = '',
        bool $hasAudio = true,
    ): array {
        $encoderFlags = VideoProcessingRules::codecFlags($this->encoderProfile->encoder);
        // libx265 and SVT maintain pools independently of FFmpeg's thread limit.
        $encoderFlags = str_replace(['log-level=error', 'fast-decode=1'], ['pools=2:frame-threads=2:log-level=error', 'lp=2:fast-decode=1'], $encoderFlags);
        $segmentDuration = (int) ceil(self::SEGMENT_DURATION);

        $args = [
            self::FFMPEG_PATH,
            '-hide_banner',
            '-nostats',
            '-loglevel',
            'error',
            '-y',
            '-threads', '2',
            '-filter_threads', '1',
            '-filter_complex_threads', '1',
        ];

        array_push($args, ...FFmpegArguments::input($this->encoderProfile));

        if ($startSegment !== null && $startSegment > 0) {
            $args[] = '-ss';
            $args[] = sprintf('%.6f', $startSegment * self::SEGMENT_DURATION);
        }

        $args[] = '-i';
        $args[] = $sourcePath;

        // One encoder process produces separate video and available audio tracks.
        $args[] = '-map';
        $args[] = sprintf('0:V:%d', $videoStreamIndex);
        if ($hasAudio) {
            $args[] = '-map';
            $args[] = sprintf('0:a:%d', $audioStreamIndex);
        }

        if ($encoderFlags !== '') {
            array_push($args, ...FFmpegArguments::flags($encoderFlags));
        }

        if ($hasAudio) {
            $audioProfile ??= AudioProfile::streamingStereo();
            $args[] = '-c:a';
            $args[] = $audioProfile->codec === 'opus' ? 'libopus' : $audioProfile->codec;
            $args[] = '-b:a';
            $args[] = (string) $audioProfile->bitrate;
            $args[] = '-ac';
            $args[] = (string) $audioProfile->channelCount;
            $args[] = '-ar';
            $args[] = (string) $audioProfile->sampleRate;
        }
        $args[] = '-threads:v';
        $args[] = '2';
        if ($hasAudio) {
            $args[] = '-threads:a';
            $args[] = '1';
        }
        if ($hasAudio && $audioFilters !== '') {
            $args[] = '-af';
            $args[] = $audioFilters;
        }

        $args[] = '-b:v';
        $args[] = (string) $tier->videoBitrate;
        $args[] = '-maxrate';
        $args[] = (string) $tier->maxBitrate;
        $args[] = '-bufsize';
        $args[] = (string) $tier->bufferSize;

        if ($videoFilters !== '') {
            $args[] = '-vf';
            $args[] = $videoFilters;
        }

        // Signed composition offsets avoid video edit-list shifts in HLS tracking while retaining AAC priming.
        array_push($args,
            '-f', 'dash', '-seg_duration', (string) $segmentDuration,
            '-dash_segment_type', 'mp4',
            '-format_options', 'movflags=+negative_cts_offsets',
            '-use_template', '1', '-use_timeline', '1',
            '-hls_playlist', '1', '-hls_master_name', 'master.m3u8',
            '-adaptation_sets', $hasAudio ? 'id=0,streams=v id=1,streams=a' : 'id=0,streams=v',
            '-init_seg_name', 'init-$RepresentationID$.mp4',
            '-media_seg_name', 'track-$RepresentationID$-seg-$Number%05d$.m4s',
            rtrim($outputDir, '/') . '/manifest.mpd',
        );

        return $args;
    }

    /**
     * Encode a single segment.
     */
    public function encodeSegment(
        TranscodeJob $job,
        TranscodeSession $session,
        string $sourcePath,
        int $segmentIndex,
        float $startTime,
        string $videoFilters,
        string $audioFilters,
    ): string {
        $tier = QualityTier::fromString($job->getQualityTierName());
        $outputPath = $this->storage->resolveSegmentPath($job->getVideoId(), $tier, $segmentIndex);

        $this->ffmpeg->encodeSegment(
            sourcePath: $sourcePath,
            startTime: $startTime,
            duration: self::SEGMENT_DURATION,
            qualityTier: $tier,
            audioProfile: $session->getAudioProfile()->jsonSerialize(),
            videoFilters: $videoFilters,
            audioFilters: $audioFilters,
            outputPath: $outputPath,
        );

        return $outputPath;
    }

    /**
     * Apply bitrate multiplier to a quality tier for hardware encoding.
     * Returns a new QualityTier with adjusted bitrates.
     */
    public function applyBitrateMultiplier(QualityTier $tier): QualityTier
    {
        if ($this->bitrateMultiplier === 1.0) {
            return $tier;
        }

        return new QualityTier(
            name: $tier->name,
            height: $tier->height,
            width: $tier->width,
            videoBitrate: (int) round($tier->videoBitrate * $this->bitrateMultiplier),
            maxBitrate: (int) round($tier->maxBitrate * $this->bitrateMultiplier),
            bufferSize: (int) round($tier->bufferSize * $this->bitrateMultiplier),
            codec: $tier->codec,
            rfc6381Codec: $tier->rfc6381Codec,
        );
    }

    public static function getSegmentDuration(): float
    {
        return self::SEGMENT_DURATION;
    }
}
