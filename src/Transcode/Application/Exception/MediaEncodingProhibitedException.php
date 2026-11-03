<?php

declare(strict_types=1);

namespace App\Transcode\Application\Exception;

use App\Shared\Application\Exception\ConflictException;

final class MediaEncodingProhibitedException extends ConflictException
{
    /** Without a kind the household profile refuses encoding; with one the deployment runs no slots of that kind. */
    public function __construct(?string $kind = null)
    {
        parent::__construct($kind === null
            ? 'Audio and video encoding is unavailable in the household deployment profile.'
            : sprintf('%s encoding and stream copy are unavailable because the deployment runs no %s slots.', ucfirst($kind), $kind), ['reason' => 'encoding_prohibited']);
    }
}
