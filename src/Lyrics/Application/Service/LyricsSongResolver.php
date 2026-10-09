<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Service;

use App\Catalog\Application\Port\SongLookupInterface;
use App\Lyrics\Application\Exception\InvalidSongPublicIdException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use InvalidArgumentException;

/**
 * Resolves the song a lyrics fetch or apply names by public ID.
 *
 * The API passes the caller's library read scope; console commands pass the unrestricted
 * scope, because CLI access is full authority.
 */
final readonly class LyricsSongResolver
{
    public function __construct(
        private SongLookupInterface $songs,
    ) {
    }

    /**
     * @throws InvalidSongPublicIdException when the public ID is malformed
     * @throws NotFoundException            when no song visible in the scope has the public ID
     */
    public function songId(string $publicId, LibraryReadScope $scope): Uuid
    {
        try {
            $resolved = PublicId::fromString($publicId);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidSongPublicIdException($exception);
        }

        return $this->songs->findVisibleSongId($resolved, $scope)
            ?? throw new NotFoundException('Song not found.');
    }
}
