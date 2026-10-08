<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Port\GenrePortInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;

/**
 * Resolves the genre and the album or song that the app:genre:album:* and app:genre:song:*
 * commands link, with the outcomes GenreController reports: an unknown genre fails, a
 * malformed album or song ID is invalid input.
 */
final readonly class GenreLinkTarget
{
    private function __construct(
        public Uuid $genreId,
        public Uuid $targetId,
    ) {
    }

    /**
     * @param string $kind `album` or `song`, as the error message names it
     *
     * @throws NotFoundException     when no genre has the slug
     * @throws InvalidInputException when the album or song ID is not a UUID
     */
    public static function resolve(GenrePortInterface $genres, string $slug, string $targetId, string $kind): self
    {
        $genre = $genres->findBySlug($slug);
        if ($genre === null) {
            throw new NotFoundException(sprintf('Genre "%s" not found.', $slug));
        }

        try {
            $target = Uuid::fromString($targetId);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidInputException(sprintf('Invalid %s ID format.', $kind), [], $exception);
        }

        return new self($genre->getId(), $target);
    }
}
