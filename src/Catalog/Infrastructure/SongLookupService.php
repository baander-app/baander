<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\Port\SongLookupInterface;
use App\Catalog\Application\Port\SongLyricSignature;
use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class SongLookupService implements SongLookupInterface
{
    public function __construct(
        private readonly SongRepositoryInterface $songs,
        private readonly AlbumRepositoryInterface $albums,
        private readonly Connection $connection,
    ) {
    }

    public function findVisibleSongId(PublicId $publicId, LibraryReadScope $scope): ?Uuid
    {
        return $this->songs->findVisibleByPublicId($publicId, $scope)?->getId();
    }

    public function findLyricSignature(Uuid $songId): ?SongLyricSignature
    {
        $song = $this->songs->findByUuid($songId);
        if ($song === null) {
            return null;
        }

        return new SongLyricSignature(
            $song->getTitle(),
            $this->songs->getArtistNameForSong($songId),
            $this->albums->findByUuid($song->getAlbumId())?->getTitle(),
            $song->getLength(),
        );
    }

    public function songIdsAfter(?Uuid $after, int $limit): array
    {
        $ids = $after === null
            ? $this->connection->fetchFirstColumn(
                'SELECT id FROM songs ORDER BY id LIMIT :limit',
                ['limit' => $limit],
                ['limit' => ParameterType::INTEGER],
            )
            : $this->connection->fetchFirstColumn(
                'SELECT id FROM songs WHERE id > CAST(:after AS uuid) ORDER BY id LIMIT :limit',
                ['after' => $after->toString(), 'limit' => $limit],
                ['limit' => ParameterType::INTEGER],
            );

        return $this->toUuids($ids);
    }

    public function visibleSongIds(array $songIds, LibraryReadScope $scope): array
    {
        if ($songIds === [] || (!$scope->isUnrestricted() && $scope->getLibraryIds() === [])) {
            return [];
        }

        $ids = self::uuidArray(array_map(static fn (Uuid $id): string => $id->toString(), $songIds));
        if ($scope->isUnrestricted()) {
            return $this->toUuids($this->connection->fetchFirstColumn(
                'SELECT s.id FROM songs s WHERE s.id = ANY(CAST(:song_ids AS uuid[]))',
                ['song_ids' => $ids],
            ));
        }

        return $this->toUuids($this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT s.id
                FROM songs s
                JOIN albums a ON a.id = s.album_id
                WHERE s.id = ANY(CAST(:song_ids AS uuid[]))
                    AND a.library_id = ANY(CAST(:library_ids AS uuid[]))
                SQL,
            ['song_ids' => $ids, 'library_ids' => self::uuidArray($scope->getLibraryIds())],
        ));
    }

    /**
     * A PostgreSQL array literal bound as one parameter, unlike an expanded IN list.
     * Canonical UUID strings need no quoting inside the literal.
     *
     * @param list<string> $ids
     */
    private static function uuidArray(array $ids): string
    {
        return '{' . implode(',', $ids) . '}';
    }

    /**
     * @param list<mixed> $ids
     * @return list<Uuid>
     */
    private function toUuids(array $ids): array
    {
        return array_map(static fn (mixed $id): Uuid => Uuid::fromString((string) $id), $ids);
    }
}
