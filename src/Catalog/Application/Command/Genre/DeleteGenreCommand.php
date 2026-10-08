<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Genre;

/**
 * Deletes a genre. Its child genres become root genres, and its album, song and movie links are removed.
 */
final readonly class DeleteGenreCommand
{
    public function __construct(
        public string $slug,
    ) {
    }
}
