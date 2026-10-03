<?php

declare(strict_types=1);

namespace App\Transcode\Application\Exception;

final class TranscodeStartupUnavailableException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Transcode startup is temporarily unavailable. Please retry.');
    }
}
