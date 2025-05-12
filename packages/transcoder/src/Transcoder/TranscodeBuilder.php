<?php

namespace Baander\Transcoder\Transcoder;

use Amp\Process\Process;
use Baander\Common\Streaming\TranscodeOptions;
use Baander\Common\Streaming\VideoProfile;
use Baander\Transcoder\TranscoderContext;
use function Illuminate\Filesystem\join_paths;

class TranscodeBuilder
{
    public function __construct(
        private TranscoderContext $context,
        private TranscodeArguments $command,
        public TranscodeOptions $options,
    ) {
        $this->command = new TranscodeArguments($this->context->ffmpegPath);
    }

    public function makeProcess(?float $seekTime = null, ?array $resolution = null): TranscodeProcess
    {
        if ($this->options->directPlay) { // Add support for direct play
            $this->command->setDirectPlayArguments($this->options->inputFilePath);
            return new TranscodeProcess($this->command, $this->context->logger);
        }

        $totalSegments = count($this->options->segmentTimes);
        if ($totalSegments < 2) {
            throw new \RuntimeException('Not enough segments to transcode. Minimum is 2.');
        }

        $startAt = $seekTime ?? $this->options->segmentTimes[0];
        $endAt = $this->options->segmentTimes[$totalSegments - 1];
        $segmentTimesCsv = $this->getSegmentTimesAsCsv();

        if ($startAt > 0) {
            $this->command->setNamedArgument('-ss', sprintf('%.6f', $startAt));
        }

        $this->command->setNamedArgument('-i', $this->options->inputFilePath);
        $this->command->setNamedArgument('-to', sprintf('%.6f', $endAt));
        $this->command->setNamedArgument('-force_key_frames', $this->buildKeyFrameExpression($segmentTimesCsv));

        $this->command->setArgument('-copyts')->setArgument('-sn');

        if ($resolution) {
            $this->applyDynamicResolution($resolution);
        } elseif ($this->options->videoProfile) {
            $this->applyVideoProfile();
        }

        if ($this->options->audioProfile) {
            $this->applyAudioProfile();
        }

        $this->command
            ->setNamedArgument('-f', 'segment')
            ->setNamedArgument('-segment_time_delta', '0.2')
            ->setNamedArgument('-segment_format', 'mpeg_ts')
            ->setNamedArgument('-segment_times', $segmentTimesCsv)
            ->setNamedArgument('-segment_start_number', sprintf('%', $this->options->segmentOffset))
            ->setNamedArgument('-segment_list_type', 'flat')
            ->setNamedArgument('segment_list', 'pipe:1')
            ->setArgument(join_paths($this->options->outputDirectoryPath, sprintf('%s-%%05d.ts', $this->options->segmentPrefix)));

        return new TranscodeProcess($this->command, $this->context->logger);
    }

    /**
     * Generate DASH-compatible video segments and MPD manifest.
     *
     * @param array $profiles An array of bitrate profiles: ['width' => ..., 'height' => ..., 'videoBitrate' => ..., 'audioBitrate' => ...].
     * @param string $outputDir Output directory for DASH files.
     * @return TranscodeProcess
     */
    public function createDASHSegmentsAndManifest(array $profiles, string $outputDir): TranscodeProcess
    {
        $manifestPath = join_paths($outputDir, 'manifest.mpd');
        $segmentPrefix = join_paths($outputDir, 'dash_segment');

        $this->command->setNamedArgument('-i', $this->options->inputFilePath);

        foreach ($profiles as $profile) {
            [$width, $height, $videoBitrate, $audioBitrate] = $profile;

            $scale = sprintf('scale=%d:%d', $width, $height);

            // Add video profile arguments
            $this->command
                ->setNamedArgument('-vf', $scale)
                ->setNamedArgument('-b:v', sprintf('%dk', $videoBitrate))
                ->setNamedArgument('-c:v', 'libx264')
                ->setNamedArgument('-preset', 'fast')
                ->setNamedArgument('-profile:v', 'main')
                ->setNamedArgument('-level:v', '4.0');

            // Add audio profile arguments
            $this->command
                ->setNamedArgument('-c:a', 'aac')
                ->setNamedArgument('-b:a', sprintf('%dk', $audioBitrate));
        }

        // Ensure placeholder variables are not escaped by PHP
        $this->command
            ->setNamedArgument('-use_timeline', '1') // Enables timeline-based addressing
            ->setNamedArgument('-use_template', '1') // Enable segment template
            ->setNamedArgument('-f', 'dash')         // Output format as DASH
            ->setNamedArgument('-init_seg_name', "{$segmentPrefix}_init.mp4")
            ->setNamedArgument('-media_seg_name', "{$segmentPrefix}_\$RepresentationID\$-\$Number%05d\$.m4s") // Correctly escaped
            ->setNamedArgument('-y', $manifestPath);

        return new TranscodeProcess($this->command, $this->context->logger);
    }


    public function buildABRHLSStream(VideoProfile $profile, string $outputDirectory): TranscodeProcess
    {
        $this->command->setNamedArgument('-i', $this->options->inputFilePath);

        // Set video and audio profiles for ABR
        $this->command->setNamedArgument('-c:v', 'libx264')
            ->setNamedArgument('-preset', 'fast')
            ->setNamedArgument('-profile:v', 'main')
            ->setNamedArgument('-level:v', '3.1')
            ->setNamedArgument('-b:v', $profile->bitrate . 'k') // Video bitrate
            ->setNamedArgument('-s', "{$profile->width}x{$profile->height}"); // Resolution

        $this->command->setNamedArgument('-c:a', 'aac')
            ->setNamedArgument('-b:a', '128k');

        // HLS-specific settings
        $playlistName = "abr_{$profile->bitrate}k.m3u8";
        $this->command->setNamedArgument('-f', 'hls')
            ->setNamedArgument('-hls_time', '10') // Segment duration in seconds
            ->setNamedArgument('-hls_playlist_type', 'vod') // Video-on-demand
            ->setNamedArgument('-hls_segment_filename', "{$outputDirectory}/seg_{$profile->bitrate}k_%03d.ts")
            ->setNamedArgument('-master_pl_name', "{$outputDirectory}/{$playlistName}");

        return new TranscodeProcess($this->command, $this->context->logger);
    }

    /**
     * Build the remuxing process.
     */
    public function buildRemuxProcess(): TranscodeProcess
    {
        $this->command->setNamedArgument('-c', 'copy'); // Copy streams without transcoding
        $this->command->setNamedArgument(
            '-output',
            join_paths($this->options->outputDirectoryPath, basename($this->options->inputFilePath) . '.mkv')
        );

        return new TranscodeProcess($this->command, $this->context->logger);
    }

    /**
     * Build the full video/audio transcoding process.
     */
    public function buildTranscodeProcess(): TranscodeProcess
    {
        // Set input file
        $this->command->setNamedArgument('-i', $this->options->inputFilePath);

        // Apply codecs, resolution, and bitrate
        $this->applyVideoProfile();
        $this->applyAudioProfile();

        // Set the output container
        $this->command->setNamedArgument(
            '-output',
            join_paths($this->options->outputDirectoryPath, basename($this->options->inputFilePath) . '.mp4')
        );

        return new TranscodeProcess($this->command, $this->context->logger);
    }


    private function applyDynamicResolution(array $resolution): void
    {
        [$width, $height] = $resolution;
        $scale = sprintf('scale=%d:%d', $width, $height);
        $this->command
            ->setNamedArgument('-vf', $scale)
            ->setNamedArgument('-c:v', 'libx264')
            ->setNamedArgument('-preset', 'faster')
            ->setNamedArgument('-profile:v', 'high')
            ->setNamedArgument('-level:v', '4.0')
            ->setNamedArgument('-b:v', sprintf('%dk', $this->options->videoProfile->bitrate));
    }

    private function applyVideoProfile(): void
    {
        if ($this->options->videoProfile->width >= $this->options->videoProfile->height) {
            $scale = sprintf('scale=-2:%d', $this->options->videoProfile->height);
        } else {
            $scale = sprintf('scale=%d:-2', $this->options->videoProfile->width);
        }

        $this->command
            ->setNamedArgument('-vf', $scale)
            ->setNamedArgument('-c:v', 'libx264')
            ->setNamedArgument('-preset', 'faster')
            ->setNamedArgument('-profile:v', 'high')
            ->setNamedArgument('-level:v', '4.0')
            ->setNamedArgument('-b:v', sprintf('%dk', $this->options->videoProfile->bitrate));
    }

    private function applyAudioProfile(): void
    {
        $this->command
            ->setNamedArgument('-c:a', 'aac')
            ->setNamedArgument('-b:a', sprintf('%dk', $this->options->audioProfile->bitrate));
    }

    private function buildKeyFrameExpression(string $segmentTimesCsv): string
    {
        return empty($segmentTimesCsv) ? 'expr:gte(t,n_forced*1)' : $segmentTimesCsv;
    }

    private function getSegmentTimesAsCsv(): string
    {
        return implode(',', array_map(fn($time) => sprintf('%.6f', $time), $this->options->segmentTimes));
    }
}