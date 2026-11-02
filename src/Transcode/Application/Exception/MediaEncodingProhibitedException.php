<?php

declare(strict_types=1);

namespace App\Transcode\Application\Exception;

use App\Shared\Application\Exception\ConflictException;

final class MediaEncodingProhibitedException extends ConflictException
{
    public function __construct()
    {
        parent::__construct('Audio and video encoding is unavailable in the household deployment profile.', ['reason' => 'encoding_prohibited']);
    }
}
