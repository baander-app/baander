<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Movie;

/**
 * Deletes a movie and the videos no other movie uses. The video files stay on disk.
 */
final readonly class DeleteMovieCommand
{
    /** @param string $publicId the movie's public ID, as given */
    public function __construct(
        public string $publicId,
    ) {
    }
}
