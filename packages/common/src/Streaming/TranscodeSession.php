<?php

namespace Baander\Common\Streaming;

use Baander\Common\Streaming\TranscodeOptions;

class TranscodeSession
{
    private bool $isActive = true;

    public function __construct(
        private readonly string           $sessionId,
        private readonly TranscodeOptions $options
    )
    {
    }

    public function transcode(): void
    {
        // Simulate segment transcoding using breakpoints
        foreach ($this->options->segmentTimes as $index => $time) {
            if (!$this->isActive) {
                break;
            }

            // Simulated transcoding logic
            echo "Transcoding segment {$index} at {$time}s..." . PHP_EOL;

            sleep(1); // Simulate processing time.

            echo "Segment {$index} complete!" . PHP_EOL;
        }

        // Notify completion
        $this->publishState('completed');
    }

    public function stop(): void
    {
        $this->isActive = false;

        // Notify stop
        $this->publishState('stopped');
    }

    private function publishState(string $status): void
    {
        // Publish the state to Redis for further usage
        // (e.g., UI updates or logging)
        $redis = new RedisClient('redis://localhost:6379');
        $redis->publish('transcoder:state', json_encode([
            'session_id' => $this->sessionId,
            'status'     => $status,
        ]));
    }
}
