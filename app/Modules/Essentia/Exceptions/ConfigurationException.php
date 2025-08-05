<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Exceptions;

/**
 * Exception for algorithm configuration errors
 */
class ConfigurationException extends EssentiaException
{
    public function __construct(string $message = "", int $code = 0, ?EssentiaException $previous = null)
    {
        parent::__construct("Configuration Error: " . $message, $code, $previous);
    }
}