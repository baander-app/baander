<?php

declare(strict_types=1);

namespace Tsduck\Exception;

/**
 * Base exception for all TSDuck PHP binding errors.
 *
 * Thrown when operations on the TSDuck native library fail,
 * when FFI resources are unavailable, or when invalid operations
 * are performed on TSDuck objects.
 */
class TsduckException extends \RuntimeException
{
}
