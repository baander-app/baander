<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Genre;

/**
 * Changes a genre's name, slug, parent or MusicBrainz ID. A null field stays as it is.
 */
final readonly class UpdateGenreCommand
{
    /**
     * @param string      $slug     the current slug of the genre to change
     * @param string|null $newSlug  the slug to give it
     * @param string|null $parentId UUID of the new parent genre
     * @param string|null $mbid     MusicBrainz ID
     */
    public function __construct(
        public string $slug,
        public ?string $name = null,
        public ?string $newSlug = null,
        public ?string $parentId = null,
        public ?string $mbid = null,
    ) {
    }
}
