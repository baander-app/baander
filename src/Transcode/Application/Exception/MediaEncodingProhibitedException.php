<?php

declare(strict_types=1);

namespace App\Transcode\Application\Exception;

use App\Shared\Application\Exception\ConflictException;

final class MediaEncodingProhibitedException extends ConflictException
{
    /** Without a kind a remux deployment refuses encoding; with one the transcode deployment runs no slots of that kind. */
    public function __construct(?string $kind = null)
    {
        parent::__construct($kind === null
            ? 'Audio and video encoding is unavailable in a remux deployment.'
            : sprintf('%s encoding and stream copy are unavailable because the deployment runs no %s slots.', ucfirst($kind), $kind), ['reason' => 'encoding_prohibited']);
    }
}
