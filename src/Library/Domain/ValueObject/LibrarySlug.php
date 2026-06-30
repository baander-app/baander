<?php

declare(strict_types=1);

namespace App\Library\Domain\ValueObject;

use Stringable;

final class LibrarySlug implements Stringable
{
    private const string PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function __construct(
        private string $slug,
    ) {
        $normalized = strtolower(trim($slug));

        if ($normalized === '') {
            throw new \InvalidArgumentException('Library slug cannot be empty.');
        }

        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw new \InvalidArgumentException(
                sprintf('Library slug "%s" must contain only lowercase letters, numbers, and hyphens.', $normalized),
            );
        }

        $this->slug = $normalized;
    }

    /**
     * Generate a slug from a library name.
     */
    public static function fromName(string $name): self
    {
        $slug = mb_strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        return new self($slug);
    }

    public function toString(): string
    {
        return $this->slug;
    }

    public function __toString(): string
    {
        return $this->slug;
    }
}
