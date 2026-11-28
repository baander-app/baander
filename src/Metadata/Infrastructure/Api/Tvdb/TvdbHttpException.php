<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\Tvdb;

use RuntimeException;

final class TvdbHttpException extends RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct('TheTVDB API returned an unsuccessful response.');
    }
}
