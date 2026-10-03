<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\Service\VideoProcessingRules;
use App\Transcode\Domain\ValueObject\ColorSpace;
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
        $profile = $session->getAudioProfile();

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
     * Produces a continuous stream of fMP4 segments via the HLS muxer.
     * One process produces all video segments for a tier — no per-segment
     * re-seek or encoder re-init.
     *
     * Selected video and audio are muxed into one rendition. Media fragments
     * are written to temporary files and renamed after close; init.mp4 is
     * separate and does not inherit this publication guarantee.
     *
     * @param string $sourcePath Path to the source video file
     * @param QualityTier $tier Target quality tier
     * @param string $videoFilters FFmpeg video filter chain (-vf), may be empty
     * @param string $outputDir Directory for segment output
     * @param ?int $startSegment Starting segment number (for seek support)
     * @param int $videoStreamIndex Source video stream index (default 0)
     * @param int $audioStreamIndex Source audio stream index (default 0)
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
    ): array {
        $encoderFlags = VideoProcessingRules::codecFlags($this->encoderProfile->encoder);
        $hwAccelFlags = $this->encoderProfile->hwaccelInputFlags();
        $decoderFlags = $this->encoderProfile->decoderFlags();
        $segmentDuration = (int) ceil(self::SEGMENT_DURATION);

        $args = [
            self::FFMPEG_PATH,
            '-hide_banner',
            '-nostats',
            '-loglevel',
            'error',
            '-y',
        ];

        // hwaccel/decoder/encoder flags come from trusted config/code as
        // space-separated token strings. Tokenize and append individually so
        // proc_open receives a real arg array and the shell is never invoked.
        if ($hwAccelFlags !== '') {
            array_push($args, ...$this->tokenize($hwAccelFlags));
        }
        if ($decoderFlags !== '') {
            array_push($args, ...$this->tokenize($decoderFlags));
        }

        if ($startSegment !== null && $startSegment > 0) {
            $args[] = '-ss';
            $args[] = sprintf('%.6f', $startSegment * self::SEGMENT_DURATION);
        }

        $args[] = '-i';
        $args[] = $sourcePath;

        // Muxed rendition: map the selected video AND audio streams into one
        // HLS variant. Each segment carries video + its audio together, so the
        // media playlist has a single variant per tier (no EXT-X-MEDIA audio
        // groups). Stream indices are encoded in the segment filename for
        // cache provenance.
        $args[] = '-map';
        $args[] = sprintf('0:v:%d', $videoStreamIndex);
        $args[] = '-map';
        $args[] = sprintf('0:a:%d', $audioStreamIndex);

        if ($encoderFlags !== '') {
            array_push($args, ...$this->tokenize($encoderFlags));
        }

        // Audio codec: AAC stereo, matching the existing audio delivery.
        $args[] = '-c:a';
        $args[] = 'aac';
        $args[] = '-b:a';
        $args[] = '128k';
        $args[] = '-ac';
        $args[] = '2';

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

        $args[] = '-f';
        $args[] = 'hls';
        $args[] = '-hls_segment_type';
        $args[] = 'fmp4';
        $args[] = '-hls_time';
        $args[] = (string) $segmentDuration;
        $args[] = '-hls_playlist_type';
        $args[] = 'vod';
        // Publish only closed media fragments at the final .m4s names.
        $args[] = '-hls_flags';
        $args[] = 'temp_file';

        if ($startSegment !== null && $startSegment > 0) {
            $args[] = '-start_number';
            $args[] = (string) $startSegment;
        }

        // Identity-encoded segment filename: v{vIdx}_a{aIdx}_{tier}_{seg}.m4s.
        // The prefix lets a cache-sweep job identify provenance and lets
        // different stream selections coexist without collision.
        $prefix = sprintf('v%d_a%d_%s', $videoStreamIndex, $audioStreamIndex, $tier->name);
        $args[] = '-hls_segment_filename';
        $args[] = rtrim($outputDir, '/') . '/' . $prefix . '_%d.m4s';
        $args[] = rtrim($outputDir, '/') . '/stream.m3u8';

        return $args;
    }

    /**
     * Tokenize a space-separated flag string into individual arg tokens.
     *
     * Trusted flag strings from VideoProcessingRules / EncoderProfile may
     * contain quoted multi-word values (e.g. -force_key_frames "expr:...").
     * preg_split with the REGEX_QUOTES pattern preserves quoted phrases as
     * single tokens. This is safe because the input is never user-controlled;
     * proc_open's array form is the real injection guard.
     *
     * @return list<string>
     */
    private function tokenize(string $flags): array
    {
        if ($flags === '') {
            return [];
        }

        // Match sequences of non-whitespace, or double-quoted strings.
        // Strip the surrounding quotes from quoted tokens so they are not
        // passed literally to proc_open — ffmpeg would then receive
        // -force_key_frames "expr:..." (quotes included) and fail with
        // "Invalid duration specification".
        preg_match_all('/"[^"]*"|\S+/', $flags, $matches);

        return array_map(
            static fn (string $token): string =>
                $token !== '' && $token[0] === '"' ? substr($token, 1, -1) : $token,
            $matches[0],
        );
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
