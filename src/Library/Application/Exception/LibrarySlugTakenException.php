<?php

declare(strict_types=1);

namespace App\Library\Application\Exception;

use App\Shared\Application\Exception\ConflictException;

/** Another library already has the slug; HTTP answers 409 and console commands fail. */
final class LibrarySlugTakenException extends ConflictException
{
    public static function forSlug(string $slug): self
    {
        return new self(sprintf('A library with the slug "%s" already exists.', $slug), ['reason' => 'slug_exists']);
    }
}
