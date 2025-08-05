<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Exceptions;

use Exception;

/**
 * Base exception for all Essentia-related errors
 */
class EssentiaException extends Exception
{
    public function __construct(string $message = "", int $code = 0, ?Exception $previous = null)
    {
        parent::__construct("Essentia Error: " . $message, $code, $previous);
    }
}