<?php

declare(strict_types=1);

namespace App\Playlist\Infrastructure\Doctrine;

use App\Playlist\Application\Port\AlbumPlaylistMergePortInterface;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistSongEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Statements rather than the aggregate's save, which flushes the whole unit of work and clears
 * the entity manager on a conflict. A playlist entity the manager holds keeps its version until
 * read again; the repository refreshes it before every write, and its save compares the version
 * and entries it read with the rows.
 */
final readonly class AlbumPlaylistMerge implements AlbumPlaylistMergePortInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function foldSongs(array $duplicates): void
    {
        $duplicateIds = array_map(static fn (array $duplicate): Uuid => $duplicate[0], $duplicates);
        $playlists = $this->lock($duplicateIds);
        if ($playlists !== []) {
            foreach ($duplicates as [$duplicate, $kept]) {
                $this->fold($duplicate, $kept, $playlists);
            }
        }
        $this->release($duplicateIds);
    }

    /**
     * Locks the playlists that hold one of the songs and raises their version, as a save of each
     * does before it writes its entries. A save takes the playlist, then its entries, and its new
     * entries take their songs only FOR KEY SHARE, which the merge's song locks allow, so a save
     * and the merge cannot wait on each other in a cycle. FOR NO KEY UPDATE is the mode the
     * version's update takes anyway; SQL, because DQL locks rows only FOR UPDATE or FOR SHARE.
     *
     * @param list<Uuid> $songs
     *
     * @return list<string> the locked playlists' IDs
     */
    private function lock(array $songs): array
    {
        if ($songs === []) {
            return [];
        }
        $connection = $this->entityManager->getConnection();
        /** @var list<string> $playlists */
        $playlists = $connection->executeQuery(
            <<<'SQL'
                SELECT playlist.id FROM playlists playlist
                WHERE playlist.id IN (SELECT entry.playlist_id FROM playlist_song entry WHERE entry.song_id IN (:songs))
                ORDER BY playlist.id
                FOR NO KEY UPDATE OF playlist
                SQL,
            ['songs' => self::ids($songs)],
            ['songs' => ArrayParameterType::STRING],
        )->fetchFirstColumn();
        if ($playlists !== []) {
            $connection->executeStatement(
                'UPDATE playlists SET persistence_version = persistence_version + 1 WHERE id IN (:playlists)',
                ['playlists' => $playlists],
                ['playlists' => ArrayParameterType::STRING],
            );
        }

        return $playlists;
    }

    /**
     * A playlist holds a song once (uniq_playlist_song_playlist_id_song_id). In a locked playlist
     * that holds the kept song the duplicate's entry goes; in the others it becomes the kept song's.
     * No other session writes the entries of a locked playlist, and each duplicate is folded by
     * statements of its own, so an entry an earlier duplicate gave the kept song is seen.
     *
     * @param list<string> $playlists
     */
    private function fold(Uuid $duplicate, Uuid $kept, array $playlists): void
    {
        $connection = $this->entityManager->getConnection();
        $parameters = ['duplicate' => $duplicate->toString(), 'kept' => $kept->toString(), 'playlists' => $playlists];
        $types = ['playlists' => ArrayParameterType::STRING];
        $connection->executeStatement(
            <<<'SQL'
                DELETE FROM playlist_song entry
                WHERE entry.song_id = :duplicate AND entry.playlist_id IN (:playlists)
                  AND EXISTS (SELECT 1 FROM playlist_song kept WHERE kept.playlist_id = entry.playlist_id AND kept.song_id = :kept)
                SQL,
            $parameters,
            $types,
        );
        $connection->executeStatement(
            'UPDATE playlist_song SET song_id = :kept WHERE song_id = :duplicate AND playlist_id IN (:playlists)',
            $parameters,
            $types,
        );
    }

    /**
     * The entries this manager holds of the duplicates no longer match their rows, or their rows
     * are gone; nothing refers to an entry. One still to be loaded reads its row as the statements
     * left it.
     *
     * @param list<Uuid> $songs
     */
    private function release(array $songs): void
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        foreach ($unitOfWork->getIdentityMap()[PlaylistSongEntity::class] ?? [] as $entry) {
            if ($entry instanceof PlaylistSongEntity
                && !$unitOfWork->isUninitializedObject($entry)
                && array_any($songs, $entry->getSongId()->equals(...))) {
                $this->entityManager->detach($entry);
            }
        }
    }

    /**
     * @param list<Uuid> $songs
     *
     * @return list<string>
     */
    private static function ids(array $songs): array
    {
        return array_map(static fn (Uuid $song): string => $song->toString(), $songs);
    }
}
