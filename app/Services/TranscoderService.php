<?php

namespace App\Services;

use Baander\Transcoder\Application;

class TranscoderService
{
    private $application;

    public function __construct(Application $application)
    {
        $this->application = $application;
    }

    public function startTranscoding(string $sessionId, array $options, int $startTime = 0): void
    {
        $transcodeOptions = $this->mapTranscodeOptions($options);
        $this->application->startTranscoding($sessionId, $transcodeOptions, $startTime);
    }

    public function stopTranscoding(string $sessionId): void
    {
        $this->application->stopTranscoding($sessionId);
    }

    public function seek(string $sessionId, array $options, int $seekTime): void
    {
        $transcodeOptions = $this->mapTranscodeOptions($options);
        $this->application->seek($sessionId, $transcodeOptions, $seekTime);
    }

    private function mapTranscodeOptions(array $options): \Baander\Common\Streaming\TranscodeOptions
    {
        $videoProfile = new \Baander\Common\Streaming\VideoProfile(
            $options['video_profile']['width'] ?? null,
            $options['video_profile']['height'] ?? null,
            $options['video_profile']['bitrate'] ?? null
        );

        $audioProfile = new \Baander\Common\Streaming\AudioProfile(
            $options['audio_profile']['bitrate'] ?? null
        );

        return new \Baander\Common\Streaming\TranscodeOptions(
            $options['input_file_path'],
            $options['output_directory_path'],
            $videoProfile,
            $audioProfile
        );
    }
}