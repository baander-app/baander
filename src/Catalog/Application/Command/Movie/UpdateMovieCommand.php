<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Movie;

/**
 * Changes a movie's title, year or summary. A null field stays as it is.
 */
final readonly class UpdateMovieCommand
{
    /**
     * @param string $publicId the movie's public ID, as given
     */
    public function __construct(
        public string $publicId,
        public ?string $title = null,
        public ?int $year = null,
        public ?string $summary = null,
    ) {
    }
}
