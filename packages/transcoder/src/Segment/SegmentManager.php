<?php

namespace Baander\Transcoder\Segment;

use Baander\Common\Streaming\TranscodeOptions;

class SegmentManager
{
    private TranscodeOptions $options;

    public function __construct(TranscodeOptions $options)
    {
        $this->options = $options;
    }

    /**
     * Prepare segment files for a specific resolution/bitrate.
     *
     * @param string $bitrateSuffix
     * @return array<string> Segment file paths.
     */
    public function generateSegments(string $bitrateSuffix): array
    {
        $outputPattern = $this->options->outputDirectoryPath . "/abr_{$bitrateSuffix}k-%05d.ts";

        // Command placeholders for FFmpeg:
        $command = sprintf(
            '%s -i %s -c:v copy -c:a copy -f segment -segment_time 10 -reset_timestamps 1 "%s"',
            escapeshellcmd($this->options->transcoderContext->ffmpegPath),
            escapeshellarg($this->options->inputFilePath),
            $outputPattern
        );

        shell_exec($command);

        // Assuming the command would create the corresponding .ts files, list those files:
        return glob($this->options->outputDirectoryPath . "/abr_{$bitrateSuffix}k-*.ts");
    }
}