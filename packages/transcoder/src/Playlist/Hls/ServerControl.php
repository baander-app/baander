<?php

namespace Baander\Transcoder\Playlist\Hls;

/**
 * Represents #EXT-X-SERVER-CONTROL for Low-Latency HLS.
 */
class ServerControl
{
    private bool $canSkipUntil = false;
    private ?float $partHoldBack = null;
    private ?float $holdBack = null;

    public function enableSkipUntil(bool $flag): self
    {
        $this->canSkipUntil = $flag;
        return $this;
    }

    public function setPartHoldBack(float $seconds): self
    {
        $this->partHoldBack = $seconds;
        return $this;
    }

    public function setHoldBack(float $seconds): self
    {
        $this->holdBack = $seconds;
        return $this;
    }

    public function toString(): string
    {
        $attributes = [
            $this->canSkipUntil ? 'CAN-SKIP-UNTIL=YES' : null,
            $this->partHoldBack !== null ? "PART-HOLD-BACK={$this->partHoldBack}" : null,
            $this->holdBack !== null ? "HOLD-BACK={$this->holdBack}" : null,
        ];

        return '#EXT-X-SERVER-CONTROL:' . implode(',', array_filter($attributes));
    }
}
