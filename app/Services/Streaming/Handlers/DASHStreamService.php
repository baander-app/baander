<?php

namespace App\Services\Streaming\Handlers;

use App\Services\Streaming\StreamServiceInterface;
use Baander\Common\Streaming\TranscodeOptions;
use Baander\Transcoder\Application;

class DASHStreamService implements StreamServiceInterface
{
    private Application $application;

    public function __construct(Application $application)
    {
        $this->application = $application;
    }

    public function startStream(string $sessionId, TranscodeOptions $options, int $startTime = 0): void
    {
        // DASH-specific preparation logic (e.g., manifest creation)
        $this->application->startTranscoding($sessionId, $options, $startTime);
    }

    public function stopStream(string $sessionId): void
    {
        $this->application->stopTranscoding($sessionId);
    }

    public function seekStream(string $sessionId, TranscodeOptions $options, int $seekTime): void
    {
        $this->application->seek($sessionId, $options, $seekTime);
    }
}