<?php

declare(strict_types=1);

namespace App\Tests\Functional\Activity\Infrastructure\Doctrine;

use App\Activity\Infrastructure\Doctrine\AlbumActivityMerge;
use App\Activity\Infrastructure\Doctrine\Entity\MediaActivityEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;

/**
 * The album merge's play statement against the migrated schema, inside the test's transaction as
 * inside a merge's. Plays name their album through a foreign key, so the albums and songs exist.
 */
final class AlbumActivityMergeTest extends TestCase
{
    private const string PLAYED_AT = '2026-11-02 21:15:00.254321+00';
    private const string UPDATED_AT = '2026-11-03 08:00:00.512345+00';

    private Uuid $source;
    private Uuid $target;
    private Uuid $other;
    private Uuid $song;
    private Uuid $otherSong;

    protected function setUp(): void
    {
        parent::setUp();
        $library = $this->library();
        $this->source = $this->album($library, 'Abbey Road (Remaster)');
        $this->target = $this->album($library, 'Abbey Road');
        $this->other = $this->album($library, 'Let It Be');
        $this->song = $this->song($this->source, 'Something');
        $this->otherSong = $this->song($this->other, 'Let It Be');
    }

    /**
     * Every play of the source names the target afterwards, also two rows one user holds for one
     * song, which no key forbids; its song, counts, loved flag and times stay. A play of another
     * album is left as it was.
     */
    public function testEveryPlayOfTheSourceNamesTheTargetAndKeepsEverythingElse(): void
    {
        $listener = $this->createTestUser()->getId();
        $plays = [
            $this->play($listener, $this->song, $this->source, love: true),
            $this->play($listener, $this->song, $this->source, love: false),
            $this->play($this->createTestUser()->getId(), $this->song, $this->source, love: false),
        ];
        $otherPlay = $this->play($listener, $this->otherSong, $this->other, love: true);

        $this->merge();

        foreach ($plays as $index => $play) {
            $this->assertSame(
                [$this->song->toString(), $this->target->toString(), 3, $index === 0, self::PLAYED_AT, self::UPDATED_AT],
                $this->playRow($play),
            );
        }
        $this->assertSame([$this->otherSong->toString(), $this->other->toString(), 3, true, self::PLAYED_AT, self::UPDATED_AT], $this->playRow($otherPlay));
    }

    /**
     * The manager has loaded a play of the source and one of another album. After the statement it
     * reads the first as naming the target, and still holds the second.
     */
    public function testThePlaysTheManagerHoldsOfTheSourceLeaveIt(): void
    {
        $listener = $this->createTestUser()->getId();
        $play = $this->play($listener, $this->song, $this->source, love: false);
        $otherPlay = $this->entityManager->find(MediaActivityEntity::class, $this->play($listener, $this->otherSong, $this->other, love: false));
        self::assertSame($this->source->toString(), $this->entityManager->find(MediaActivityEntity::class, $play)?->getAlbum()?->getId()->toString());
        self::assertInstanceOf(MediaActivityEntity::class, $otherPlay);

        $this->merge();

        $this->assertSame($this->target->toString(), $this->entityManager->find(MediaActivityEntity::class, $play)?->getAlbum()?->getId()->toString());
        $this->assertTrue($this->entityManager->contains($otherPlay));
        $this->entityManager->flush();
        $this->assertSame($this->target->toString(), $this->playRow($play)[1]);
    }

    private function merge(): void
    {
        new AlbumActivityMerge($this->entityManager)->merge($this->source, $this->target);
    }

    private function library(): Uuid
    {
        $id = Uuid::v7();
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO libraries (id, public_id, slug, name, path, type, sort_order, created_at, updated_at) VALUES (?, ?, ?, 'Rock', ?, 'music', 0, clock_timestamp(), clock_timestamp())",
            [$id->toString(), (new PublicId())->toString(), 'rock-' . bin2hex(random_bytes(4)), '/music/' . bin2hex(random_bytes(4))],
        );

        return $id;
    }

    private function album(Uuid $library, string $title): Uuid
    {
        $id = Uuid::v7();
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO albums (id, public_id, library_id, title, type, created_at, updated_at) VALUES (?, ?, ?, ?, 'album', clock_timestamp(), clock_timestamp())",
            [$id->toString(), (new PublicId())->toString(), $library->toString(), $title],
        );

        return $id;
    }

    private function song(Uuid $album, string $title): Uuid
    {
        $id = Uuid::v7();
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO songs (id, public_id, album_id, title, path, size, mime_type, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1000000, 'audio/flac', clock_timestamp(), clock_timestamp())",
            [$id->toString(), (new PublicId())->toString(), $album->toString(), $title, '/music/' . $id->toString() . '.flac'],
        );

        return $id;
    }

    /** Stores the user's three plays of the song on the album, as the player records them. */
    private function play(Uuid $userId, Uuid $song, Uuid $album, bool $love): Uuid
    {
        $id = Uuid::v7();
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO media_activities (id, public_id, user_id, activity_type, song_id, album_id, play_count, love, last_played_at, created_at, updated_at) VALUES (?, ?, ?, 'play', ?, ?, 3, ?, ?, ?, ?)",
            [$id->toString(), (new PublicId())->toString(), $userId->toString(), $song->toString(), $album->toString(), $love ? 'true' : 'false', self::PLAYED_AT, self::PLAYED_AT, self::UPDATED_AT],
        );

        return $id;
    }

    /** @return array{string, string, int, bool, string, string} song, album, play count, loved flag, last played and updated time */
    private function playRow(Uuid $playId): array
    {
        $row = $this->entityManager->getConnection()->fetchNumeric(
            'SELECT song_id, album_id, play_count, love, last_played_at, updated_at FROM media_activities WHERE id = ?',
            [$playId->toString()],
        );
        self::assertIsArray($row);

        return [(string) $row[0], (string) $row[1], (int) $row[2], (bool) $row[3], (string) $row[4], (string) $row[5]];
    }
}
