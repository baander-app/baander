<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\Tvdb;

use RuntimeException;

final class TvdbTransportException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('TheTVDB API request failed.');
    }
}
