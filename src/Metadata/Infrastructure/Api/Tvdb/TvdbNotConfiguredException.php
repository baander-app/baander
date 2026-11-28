<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\Tvdb;

use RuntimeException;

final class TvdbNotConfiguredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('TheTVDB API key is not configured.');
    }
}
