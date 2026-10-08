<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Genre;

/**
 * Creates a genre, optionally below an existing parent genre.
 */
final readonly class CreateGenreCommand
{
    /**
     * @param string|null $parentId UUID of the parent genre; null creates a root genre
     * @param string|null $mbid     MusicBrainz ID
     */
    public function __construct(
        public string $name,
        public string $slug,
        public ?string $parentId = null,
        public ?string $mbid = null,
    ) {
    }
}
