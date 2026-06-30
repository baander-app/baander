<?php

declare(strict_types=1);

namespace App\Library\Domain\ValueObject;

use Stringable;
use ValueError;

final class LibraryPath implements Stringable
{
    public function __construct(
        private string $path,
    ) {
        $normalized = rtrim($path, '/\\');

        if ($normalized === '' || $normalized === '.') {
            throw new \InvalidArgumentException('Library path cannot be empty.');
        }

        if (!str_starts_with($normalized, '/')) {
            throw new \InvalidArgumentException('Library path must be absolute.');
        }

        // Block obvious traversal attempts
        if (str_contains($normalized, '..')) {
            throw new \InvalidArgumentException('Library path cannot contain directory traversal sequences.');
        }

        $this->path = $normalized;
    }

    public function toString(): string
    {
        return $this->path;
    }

    public function isWithin(string $basePath): bool
    {
        $base = rtrim($basePath, '/\\');
        $resolved = realpath($this->path);

        if ($resolved === false) {
            return false;
        }

        return str_starts_with($resolved, $base);
    }

    public function __toString(): string
    {
        return $this->path;
    }
}
