<?php

namespace App\Services\Streaming;

use Baander\Common\Streaming\TranscodeOptions;

interface StreamServiceInterface
{
    public function startStream(string $sessionId, TranscodeOptions $options, int $startTime = 0): void;

    public function stopStream(string $sessionId): void;

    public function seekStream(string $sessionId, TranscodeOptions $options, int $seekTime): void;
}
