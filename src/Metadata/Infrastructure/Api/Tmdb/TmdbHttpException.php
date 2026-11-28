<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\Tmdb;

use RuntimeException;

final class TmdbHttpException extends RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct('TMDB API returned an unsuccessful response.');
    }
}
