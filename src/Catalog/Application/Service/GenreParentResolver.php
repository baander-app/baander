<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service;

use App\Catalog\Application\Port\GenrePortInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;

/**
 * Turns a requested parent genre ID into the ID of an existing genre.
 */
final readonly class GenreParentResolver
{
    public function __construct(
        private GenrePortInterface $genres,
    ) {
    }

    /** @throws InvalidInputException when the ID is malformed or names no genre */
    public function resolve(string $parentId): Uuid
    {
        try {
            $id = Uuid::fromString($parentId);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidInputException('Invalid parent ID format.', ['parentId' => [$exception->getMessage()]], $exception);
        }

        if ($this->genres->findByUuid($id) === null) {
            throw new InvalidInputException('Parent genre not found.', ['parentId' => [sprintf('No genre has the ID "%s".', $parentId)]]);
        }

        return $id;
    }
}
