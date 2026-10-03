<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

final class TranscodeLoopOwnershipLost extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Transcode encoding loop no longer owns its lease.');
    }
}
