<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure\Doctrine;

use App\Activity\Application\Port\AlbumActivityMergePortInterface;
use App\Activity\Infrastructure\Doctrine\Entity\MediaActivityEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AlbumActivityMerge implements AlbumActivityMergePortInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function merge(Uuid $source, Uuid $target): void
    {
        // The activity's own updated time stays: nothing the listener did changed.
        $this->entityManager
            ->createQuery('UPDATE ' . MediaActivityEntity::class . ' activity SET activity.album = :target WHERE activity.album = :source')
            ->setParameter('source', $source)
            ->setParameter('target', $target)
            ->execute();
        // Reading the album's ID does not load a lazy album.
        $this->release(static fn (MediaActivityEntity $activity): bool => $activity->getAlbum()?->getId()->equals($source) === true);
    }

    #[\Override]
    public function foldSong(Uuid $duplicate, Uuid $kept): void
    {
        $connection = $this->entityManager->getConnection();
        // No unique key holds one row per user and song (RecordPlayHandler finds, then inserts), so a
        // user may hold several rows of either song. The rows of both songs of every user who played
        // the duplicate are locked first, in ID order: a play being recorded holds its row, so the
        // lock waits for it, and the next statement, which reads after the lock, counts it. A row
        // stored after the lock is left as it is: a first play of the duplicate goes with it, one of
        // the kept song stays a row of its own. SQL, because DQL locks no row with a subquery's
        // filter and has neither window functions nor a data-modifying WITH.
        $plays = $connection->executeQuery(
            <<<'SQL'
                SELECT activity.id FROM media_activities activity
                WHERE activity.song_id IN (:duplicate, :kept)
                  AND activity.user_id IN (SELECT listener.user_id FROM media_activities listener WHERE listener.song_id = :duplicate)
                ORDER BY activity.id
                FOR UPDATE OF activity
                SQL,
            ['duplicate' => $duplicate->toString(), 'kept' => $kept->toString()],
        )->fetchFirstColumn();

        if ($plays !== []) {
            // Per user, the row that stays is the oldest of the kept song, else of the duplicate, and
            // it takes the sum of the counts, the latest last play with its platform and player, the
            // loved flag of any row and the latest change; the user's other rows go. The created time
            // stays the row's own.
            $connection->executeStatement(
                <<<'SQL'
                    WITH plays AS (
                        SELECT id, user_id, play_count, love, last_played_at, last_platform, last_player, updated_at,
                            row_number() OVER (PARTITION BY user_id ORDER BY song_id = :kept DESC, created_at, id) AS place
                        FROM media_activities
                        WHERE id IN (:plays)
                    ),
                    latest AS (
                        SELECT DISTINCT ON (user_id) user_id, last_played_at, last_platform, last_player
                        FROM plays
                        ORDER BY user_id, last_played_at DESC NULLS LAST, id
                    ),
                    totals AS (
                        SELECT user_id, sum(play_count) AS play_count, bool_or(love) AS love, max(updated_at) AS updated_at
                        FROM plays
                        GROUP BY user_id
                    ),
                    dropped AS (
                        DELETE FROM media_activities WHERE id IN (SELECT id FROM plays WHERE place > 1)
                    )
                    UPDATE media_activities activity
                    SET song_id = :kept, play_count = totals.play_count, love = totals.love,
                        last_played_at = latest.last_played_at, last_platform = latest.last_platform, last_player = latest.last_player,
                        updated_at = totals.updated_at
                    FROM plays survivor
                    JOIN totals ON totals.user_id = survivor.user_id
                    JOIN latest ON latest.user_id = survivor.user_id
                    WHERE activity.id = survivor.id AND survivor.place = 1
                    SQL,
                ['kept' => $kept->toString(), 'plays' => $plays],
                ['plays' => ArrayParameterType::STRING],
            );
        }

        // A play of either song the manager holds would otherwise be handed back as it was before
        // the statements, by a find of its ID and by the repository's lookup of a user's play of
        // a song, and a save of it would write its old count and song back.
        $this->release(static fn (MediaActivityEntity $activity): bool => ($activity->getSong()?->getId() ?? null) |> (
            static fn (?Uuid $song): bool => $song !== null && ($song->equals($duplicate) || $song->equals($kept))
        ));
    }

    /**
     * The activities this manager holds that the statement changed no longer match their rows, or
     * their rows are gone; nothing refers to an activity. One still to be loaded reads its row as
     * the statement left it.
     *
     * @param \Closure(MediaActivityEntity): bool $changed
     */
    private function release(\Closure $changed): void
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        foreach ($unitOfWork->getIdentityMap()[MediaActivityEntity::class] ?? [] as $activity) {
            if ($activity instanceof MediaActivityEntity
                && !$unitOfWork->isUninitializedObject($activity)
                && $changed($activity)) {
                $this->entityManager->detach($activity);
            }
        }
    }
}
