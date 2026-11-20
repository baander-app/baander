<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LogViewer;

/** Index retention pressure suspends collection without declaring the origin unavailable. */
final class LogStorageLimitException extends \RuntimeException {}
