<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

/**
 * The catalog path, or a symlink on it, leads outside its library's root, so the file is not the
 * library's to serve. A missing library or file is reported by the parent type instead.
 */
final class LibraryMediaFileRefusedException extends \InvalidArgumentException
{
}
