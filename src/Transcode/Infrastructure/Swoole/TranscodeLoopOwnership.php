<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

/** Local observed ownership only; this does not fence database writes. */
final class TranscodeLoopOwnership
{
    private bool $lost = false;
    private bool $closed = false;

    public function markLost(): void
    {
        $this->lost = true;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isActive(): bool
    {
        return !$this->lost && !$this->closed;
    }

    public function isLost(): bool
    {
        return $this->lost;
    }

    public function assertOwned(): void
    {
        if (!$this->isActive()) {
            throw new TranscodeLoopOwnershipLost();
        }
    }
}
