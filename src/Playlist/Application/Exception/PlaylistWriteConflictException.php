<?php

declare(strict_types=1);

namespace App\Playlist\Application\Exception;

use App\Shared\Application\Exception\ConflictException;

final class PlaylistWriteConflictException extends ConflictException
{
    public function __construct()
    {
        parent::__construct('The playlist has changed. Refresh it before trying again.');
    }
}
