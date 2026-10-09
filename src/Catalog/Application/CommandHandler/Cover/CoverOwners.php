<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Cover;

use App\Catalog\Application\Command\Cover\CoverOwner;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Artist;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use InvalidArgumentException;

/**
 * Finds and saves the album or artist whose cover the cover use cases change.
 */
final readonly class CoverOwners
{
    public function __construct(
        private AlbumPortInterface $albums,
        private ArtistPortInterface $artists,
    ) {
    }

    /**
     * @throws InvalidInputException when the public ID is malformed
     * @throws NotFoundException     when no album or artist has the public ID
     */
    public function find(CoverOwner $kind, string $publicId): Album|Artist
    {
        try {
            $id = PublicId::fromString($publicId);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidInputException('Invalid public ID format.', [], $exception);
        }

        $owner = match ($kind) {
            CoverOwner::Album => $this->albums->findByPublicId($id),
            CoverOwner::Artist => $this->artists->findByPublicId($id),
        };
        if ($owner === null) {
            throw new NotFoundException(sprintf('%s "%s" not found.', $kind->label(), $publicId));
        }

        return $owner;
    }

    public function save(Album|Artist $owner): void
    {
        if ($owner instanceof Album) {
            $this->albums->save($owner);

            return;
        }

        $this->artists->save($owner);
    }
}
