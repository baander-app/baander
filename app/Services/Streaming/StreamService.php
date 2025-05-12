<?php

namespace App\Services\Streaming;

use  App\Models\TranscodingSession;
use Baander\Common\Streaming\TranscodeOptions;

class StreamService implements StreamServiceInterface
{
    private array $protocolHandlers;

    public function __construct(array $protocolHandlers = [])
    {
        $this->protocolHandlers = $protocolHandlers;
    }

    public function startStream(string $sessionId, TranscodeOptions $options, int $startTime = 0): void
    {
        TranscodingSession::create([
            'session_id' => $sessionId,
            'protocol'   => $options->protocol,
            'options'    => $options,
        ]);

        $handler = $this->resolveHandler($options->protocol);
        $handler->startStream($sessionId, $options, $startTime);
    }

    public function stopStream(string $sessionId): void
    {
        TranscodingSession::whereSessionId($sessionId)->delete();

        foreach ($this->protocolHandlers as $handler) {
            $handler->stopStream($sessionId);
        }
    }

    public function seekStream(string $sessionId, TranscodeOptions $options, int $seekTime): void
    {
        $handler = $this->resolveHandler($options->protocol);
        $handler->seekStream($sessionId, $options, $seekTime);
    }

    private function resolveHandler(string $protocol): StreamServiceInterface
    {
        if (!isset($this->protocolHandlers[$protocol])) {
            throw new \InvalidArgumentException("Protocol {$protocol} not supported.");
        }
        return $this->protocolHandlers[$protocol];
    }
}