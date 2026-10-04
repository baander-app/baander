<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Query;

use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;

final class CatalogReadScopeQuery
{
    public static function apply(QueryBuilder $qb, LibraryReadScope $scope, string $kind, string $alias): void
    {
        if ($scope->isUnrestricted()) {
            return;
        }
        if ($scope->getLibraryIds() === []) {
            $qb->andWhere('1 = 0');
            return;
        }
        $album = 'App\\Catalog\\Infrastructure\\Doctrine\\Entity\\AlbumEntity';
        $artistAlbum = 'App\\Catalog\\Infrastructure\\Doctrine\\Entity\\ArtistAlbumEntity';
        $artistSong = 'App\\Catalog\\Infrastructure\\Doctrine\\Entity\\ArtistSongEntity';
        $predicate = match ($kind) {
            'album', 'movie' => "IDENTITY($alias.library) IN (:visible_libraries)",
            'song' => <<<DQL
                $alias.album IN (
                    SELECT visible_album.id
                    FROM $album visible_album
                    WHERE IDENTITY(visible_album.library) IN (:visible_libraries)
                )
                DQL,
            'artist' => <<<DQL
                EXISTS (
                    SELECT visible_aa.id
                    FROM $artistAlbum visible_aa
                    JOIN visible_aa.album visible_a
                    WHERE visible_aa.artist = $alias.id
                        AND IDENTITY(visible_a.library) IN (:visible_libraries)
                ) OR EXISTS (
                    SELECT visible_as.id
                    FROM $artistSong visible_as
                    JOIN visible_as.song visible_s
                    JOIN visible_s.album visible_sa
                    WHERE visible_as.artist = $alias.id
                        AND IDENTITY(visible_sa.library) IN (:visible_libraries)
                )
                DQL,
            default => throw new \InvalidArgumentException('Unsupported catalog scope kind.'),
        };
        $qb->andWhere('(' . $predicate . ')')
            ->setParameter('visible_libraries', $scope->getLibraryIds(), ArrayParameterType::STRING);
    }

    /** @return array{predicate: string, parameters: array<string, mixed>, types: array<string, ArrayParameterType>} */
    public static function native(LibraryReadScope $scope, string $kind, SearchOptions $options): array
    {
        $parameters = [];
        $types = [];
        $predicate = 'TRUE';
        if (!$scope->isUnrestricted()) {
            if ($scope->getLibraryIds() === []) {
                $predicate = 'FALSE';
            } else {
                $parameters['visible_libraries'] = $scope->getLibraryIds();
                $types['visible_libraries'] = ArrayParameterType::STRING;
                $predicate = match ($kind) {
                    'album', 'movie' => 's.library_id IN (:visible_libraries)',
                    'song' => <<<'SQL'
                        EXISTS (
                            SELECT 1
                            FROM albums visible_a
                            WHERE visible_a.id = s.album_id
                                AND visible_a.library_id IN (:visible_libraries)
                        )
                        SQL,
                    'artist' => <<<'SQL'
                        EXISTS (
                            SELECT 1
                            FROM artist_album visible_aa
                            JOIN albums visible_a ON visible_a.id = visible_aa.album_id
                            WHERE visible_aa.artist_id = s.id
                                AND visible_a.library_id IN (:visible_libraries)
                        ) OR EXISTS (
                            SELECT 1
                            FROM artist_song visible_as
                            JOIN songs visible_s ON visible_s.id = visible_as.song_id
                            JOIN albums visible_sa ON visible_sa.id = visible_s.album_id
                            WHERE visible_as.artist_id = s.id
                                AND visible_sa.library_id IN (:visible_libraries)
                        )
                        SQL,
                    default => throw new \InvalidArgumentException('Unsupported catalog scope kind.'),
                };
            }
        }
        $clauses = ['(' . $predicate . ')'];
        foreach ($options->getFilters() as $index => $filter) {
            $parameter = 'visible_filter_' . $index;
            if ($filter['field'] === 'genre' && in_array($kind, ['artist', 'album'], true)) {
                $parameters[$parameter] = $filter['value'];
                $join = $kind === 'artist' ? 'JOIN artist_song vf_as ON vf_as.song_id = vf_s.id' : '';
                $owner = $kind === 'artist' ? 'vf_as.artist_id = s.id' : 'vf_s.album_id = s.id';
                $library = '';
                if ($kind === 'artist' && !$scope->isUnrestricted()) {
                    $library = $scope->getLibraryIds() === []
                        ? ' AND FALSE'
                        : ' AND vf_a.library_id IN (:visible_libraries)';
                }
                $clauses[] = <<<SQL
                    EXISTS (
                        SELECT 1
                        FROM songs vf_s
                        JOIN albums vf_a ON vf_a.id = vf_s.album_id
                        JOIN genre_song vf_gs ON vf_gs.song_id = vf_s.id
                        JOIN genres vf_g ON vf_g.id = vf_gs.genre_id
                        $join
                        WHERE $owner AND vf_g.slug = :$parameter$library
                    )
                    SQL;
            }
            if ($filter['field'] === 'artistId' && in_array($kind, ['album', 'song'], true)) {
                $parameters[$parameter] = $filter['value'];
                $link = $kind === 'album' ? 'artist_album' : 'artist_song';
                $column = $kind === 'album' ? 'album_id' : 'song_id';
                $clauses[] = <<<SQL
                    EXISTS (
                        SELECT 1
                        FROM $link vf_link
                        JOIN artists vf_artist ON vf_artist.id = vf_link.artist_id
                        WHERE vf_link.$column = s.id AND vf_artist.public_id = :$parameter
                    )
                    SQL;
            }
            if ($kind === 'song' && $filter['field'] === 'albumId') {
                $parameters[$parameter] = $filter['value'];
                $clauses[] = <<<SQL
                    EXISTS (
                        SELECT 1
                        FROM albums vf_album
                        WHERE vf_album.id = s.album_id AND vf_album.public_id = :$parameter
                    )
                    SQL;
            }
            if ($kind === 'song' && $filter['field'] === 'publicIds' && is_string($filter['value']) && $filter['value'] !== '') {
                $parameters[$parameter] = array_values(array_filter(array_map('trim', explode(',', $filter['value']))));
                $types[$parameter] = ArrayParameterType::STRING;
                $clauses[] = "s.public_id IN (:$parameter)";
            }
            if ($kind === 'song' && $filter['field'] === 'genres' && is_string($filter['value']) && $filter['value'] !== '') {
                $exclude = str_starts_with($filter['value'], '!');
                $names = array_values(array_filter(array_map('trim', explode(',', ltrim($filter['value'], '!')))));
                if ($names !== []) {
                    $parameters[$parameter] = $names;
                    $types[$parameter] = ArrayParameterType::STRING;
                    $operator = $exclude ? 'NOT EXISTS' : 'EXISTS';
                    $clauses[] = <<<SQL
                        $operator (
                            SELECT 1
                            FROM genre_song vf_gs
                            JOIN genres vf_g ON vf_g.id = vf_gs.genre_id
                            WHERE vf_gs.song_id = s.id AND vf_g.slug IN (:$parameter)
                        )
                        SQL;
                }
            }
        }
        return ['predicate' => implode(' AND ', $clauses), 'parameters' => $parameters, 'types' => $types];
    }
}
