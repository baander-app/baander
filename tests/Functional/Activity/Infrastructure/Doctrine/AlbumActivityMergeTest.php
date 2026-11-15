<?php

declare(strict_types=1);

namespace App\Tests\Functional\Activity\Infrastructure\Doctrine;

use App\Activity\Infrastructure\Doctrine\AlbumActivityMerge;
use App\Activity\Infrastructure\Doctrine\Entity\MediaActivityEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;

/**
 * The album merge's play statements against the migrated schema, inside the test's transaction as
 * inside a merge's. Plays name their album and song through foreign keys, so the albums and songs
 * exist.
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

    /**
     * A user played the source's copy of a target song three times, last on the web, and loved it,
     * and the target's song twice, earlier, on the app. Afterwards the user has one play row of the
     * target's song, that song's own row: five plays, the copy's last-played time with its platform
     * and player, loved, and changed when the copy's row last was. Its album stays the one it named.
     */
    public function testOneUsersPlaysOfBothSongsBecomeOneRowOfTheKeptSongWithTheCountsAddedAndTheLatestPlay(): void
    {
        [$kept, $duplicate] = $this->copiedSong();
        $listener = $this->createTestUser()->getId();
        $this->songPlay($listener, $duplicate, $this->source, count: 3, love: true, lastPlayedAt: '2026-11-10 20:00:00.5+00', platform: 'web', player: 'Firefox');
        $keptPlay = $this->songPlay($listener, $kept, $this->target, count: 2, love: false, lastPlayedAt: '2026-11-01 08:00:00.25+00', platform: 'ios', player: 'Baander');
        $publicId = $this->publicIdOf($keptPlay);

        $this->foldSong($duplicate, $kept);

        $this->assertSame(
            [[$keptPlay->toString(), $kept->toString(), $this->target->toString(), 5, true, '2026-11-10 20:00:00.5+00', 'web', 'Firefox', '2026-11-10 20:00:00.5+00']],
            $this->playsOf($listener),
        );
        $this->assertSame($publicId, $this->publicIdOf($keptPlay));
    }

    /**
     * A user who only played the copy keeps that row, its public ID and its loved flag, now naming
     * the target's song. A user who holds two rows of the copy ends with one, the older, with the
     * plays of both. A user who played only the target's song, in two rows, and a play of another
     * song are left as they were.
     */
    public function testAUserWhoOnlyPlayedTheDuplicateKeepsThatRowAndTwoRowsOfOneSongBecomeOne(): void
    {
        [$kept, $duplicate] = $this->copiedSong();
        $copyFan = $this->createTestUser()->getId();
        $copyPlay = $this->songPlay($copyFan, $duplicate, $this->source, count: 3, love: true, lastPlayedAt: '2026-11-10 20:00:00.5+00', platform: 'web', player: 'Firefox');
        $publicId = $this->publicIdOf($copyPlay);
        $otherPlay = $this->play($copyFan, $this->otherSong, $this->other, love: false);
        $twice = $this->createTestUser()->getId();
        $older = $this->songPlay($twice, $duplicate, $this->source, count: 3, love: false, lastPlayedAt: '2026-11-03 07:00:00+00', platform: 'web', player: 'Firefox');
        $this->songPlay($twice, $duplicate, $this->source, count: 4, love: false, lastPlayedAt: '2026-11-04 07:00:00+00', platform: 'android', player: 'Baander');
        $keptFan = $this->createTestUser()->getId();
        $this->songPlay($keptFan, $kept, $this->target, count: 1, love: false, lastPlayedAt: '2026-11-05 07:00:00+00', platform: 'web', player: 'Firefox');
        $this->songPlay($keptFan, $kept, $this->target, count: 2, love: false, lastPlayedAt: '2026-11-06 07:00:00+00', platform: 'web', player: 'Firefox');
        $keptFanBefore = $this->playsOf($keptFan);

        $this->foldSong($duplicate, $kept);

        $this->assertSame(
            [
                [$copyPlay->toString(), $kept->toString(), $this->source->toString(), 3, true, '2026-11-10 20:00:00.5+00', 'web', 'Firefox', '2026-11-10 20:00:00.5+00'],
                [$otherPlay->toString(), $this->otherSong->toString(), $this->other->toString(), 3, false, self::PLAYED_AT, null, null, self::UPDATED_AT],
            ],
            $this->playsOf($copyFan),
        );
        $this->assertSame($publicId, $this->publicIdOf($copyPlay));
        $this->assertSame(
            [[$older->toString(), $kept->toString(), $this->source->toString(), 7, false, '2026-11-04 07:00:00+00', 'android', 'Baander', '2026-11-04 07:00:00+00']],
            $this->playsOf($twice),
        );
        $this->assertCount(2, $keptFanBefore);
        $this->assertSame($keptFanBefore, $this->playsOf($keptFan));
    }

    /**
     * The manager has loaded a user's play of the copy, which the fold deletes, and that user's
     * play of the target's song, which it adds up. After the fold it finds none of the first,
     * reads the second as the sum, and a flush leaves the rows as the fold wrote them.
     */
    public function testThePlaysTheManagerHoldsOfEitherSongLeaveIt(): void
    {
        [$kept, $duplicate] = $this->copiedSong();
        $listener = $this->createTestUser()->getId();
        $copyPlay = $this->songPlay($listener, $duplicate, $this->source, count: 3, love: true, lastPlayedAt: '2026-11-10 20:00:00.5+00', platform: 'web', player: 'Firefox');
        $keptPlay = $this->songPlay($listener, $kept, $this->target, count: 2, love: false, lastPlayedAt: '2026-11-01 08:00:00.25+00', platform: 'ios', player: 'Baander');
        self::assertSame($duplicate->toString(), $this->entityManager->find(MediaActivityEntity::class, $copyPlay)?->getSong()?->getId()->toString());
        self::assertSame(2, $this->entityManager->find(MediaActivityEntity::class, $keptPlay)?->getPlayCount());

        $this->foldSong($duplicate, $kept);

        $this->assertNull($this->entityManager->find(MediaActivityEntity::class, $copyPlay));
        $this->assertSame(5, $this->entityManager->find(MediaActivityEntity::class, $keptPlay)?->getPlayCount());
        $this->entityManager->flush();
        $this->assertSame(
            [[$keptPlay->toString(), $kept->toString(), $this->target->toString(), 5, true, '2026-11-10 20:00:00.5+00', 'web', 'Firefox', '2026-11-10 20:00:00.5+00']],
            $this->playsOf($listener),
        );
    }

    private function merge(): void
    {
        new AlbumActivityMerge($this->entityManager)->merge($this->source, $this->target);
    }

    private function foldSong(Uuid $duplicate, Uuid $kept): void
    {
        new AlbumActivityMerge($this->entityManager)->foldSong($duplicate, $kept);
    }

    /** @return array{Uuid, Uuid} a target song and the source's copy of it */
    private function copiedSong(): array
    {
        return [$this->song($this->target, 'Come Together'), $this->song($this->source, 'Come Together')];
    }

    /**
     * Stores the user's plays of the song on the album, as the player records them: $count plays,
     * the last at $lastPlayedAt from $platform and $player, which is also when the row last changed.
     */
    private function songPlay(Uuid $userId, Uuid $song, Uuid $album, int $count, bool $love, string $lastPlayedAt, string $platform, string $player): Uuid
    {
        $id = Uuid::v7();
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO media_activities (id, public_id, user_id, activity_type, song_id, album_id, play_count, love, last_played_at, last_platform, last_player, created_at, updated_at) VALUES (?, ?, ?, 'play', ?, ?, ?, ?, ?, ?, ?, clock_timestamp(), ?)",
            [$id->toString(), (new PublicId())->toString(), $userId->toString(), $song->toString(), $album->toString(), $count, $love ? 'true' : 'false', $lastPlayedAt, $platform, $player, $lastPlayedAt],
        );

        return $id;
    }

    /**
     * @return list<array{string, ?string, ?string, int, bool, ?string, ?string, ?string, string}> ID, song, album, play count, loved flag,
     *                                                                                            last played time, platform, player and updated time of each of the user's plays
     */
    private function playsOf(Uuid $userId): array
    {
        return array_map(
            static fn (array $row): array => [
                (string) $row[0],
                $row[1] === null ? null : (string) $row[1],
                $row[2] === null ? null : (string) $row[2],
                (int) $row[3],
                (bool) $row[4],
                $row[5] === null ? null : (string) $row[5],
                $row[6] === null ? null : (string) $row[6],
                $row[7] === null ? null : (string) $row[7],
                (string) $row[8],
            ],
            $this->entityManager->getConnection()->fetchAllNumeric(
                'SELECT id, song_id, album_id, play_count, love, last_played_at, last_platform, last_player, updated_at FROM media_activities WHERE user_id = ? ORDER BY id',
                [$userId->toString()],
            ),
        );
    }

    private function publicIdOf(Uuid $playId): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne('SELECT public_id FROM media_activities WHERE id = ?', [$playId->toString()]);
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
