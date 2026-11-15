<?php

declare(strict_types=1);

namespace App\Lyrics\Infrastructure\Doctrine;

use App\Lyrics\Application\Port\AlbumLyricsMergePortInterface;
use App\Lyrics\Infrastructure\Doctrine\Entity\LyricsEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AlbumLyricsMerge implements AlbumLyricsMergePortInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function foldSong(Uuid $duplicate, Uuid $kept): void
    {
        // A song holds one lyrics row (uniq_lyrics_song_id). One statement takes the duplicate's
        // row away and writes it back for the kept song. ON CONFLICT drops it when the kept song
        // has lyrics, also lyrics a fetch has stored but not yet committed: the insert waits for
        // the fetch and yields once it commits, as the fetch's own insert does
        // (LyricsRepository::add). An update guarded by NOT EXISTS would miss the uncommitted row
        // and then fail on the key. SQL, because DQL has neither a data-modifying WITH nor
        // ON CONFLICT.
        $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                WITH moved AS (
                    DELETE FROM lyrics
                    WHERE song_id = :duplicate
                    RETURNING id, plain_lyrics, synced_lyrics, source, source_url, lrclib_id, is_instrumental, created_at, updated_at
                )
                INSERT INTO lyrics (id, song_id, plain_lyrics, synced_lyrics, source, source_url, lrclib_id, is_instrumental, created_at, updated_at)
                SELECT id, :kept, plain_lyrics, synced_lyrics, source, source_url, lrclib_id, is_instrumental, created_at, updated_at FROM moved
                ON CONFLICT (song_id) DO NOTHING
                SQL,
            ['duplicate' => $duplicate->toString(), 'kept' => $kept->toString()],
        );
        $this->release($duplicate);
    }

    /**
     * The lyrics this manager holds of the duplicate name it, which their row no longer does, or
     * their row is gone; nothing refers to lyrics. Lyrics still to be loaded read their row as the
     * statement left it.
     */
    private function release(Uuid $song): void
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        foreach ($unitOfWork->getIdentityMap()[LyricsEntity::class] ?? [] as $lyrics) {
            if ($lyrics instanceof LyricsEntity
                && !$unitOfWork->isUninitializedObject($lyrics)
                && $lyrics->getSongId()->equals($song)) {
                $this->entityManager->detach($lyrics);
            }
        }
    }
}
