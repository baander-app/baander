<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Filesystem;

use InvalidArgumentException;

/** The canonical path, every symlink on it resolved, lies outside the base path it was checked against. */
final class PathOutsideBaseException extends InvalidArgumentException
{
}
