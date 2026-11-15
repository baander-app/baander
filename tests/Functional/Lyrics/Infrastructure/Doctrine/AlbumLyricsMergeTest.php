<?php

declare(strict_types=1);

namespace App\Tests\Functional\Lyrics\Infrastructure\Doctrine;

use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Lyrics\Infrastructure\Doctrine\AlbumLyricsMerge;
use App\Lyrics\Infrastructure\Doctrine\Entity\LyricsEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;

/**
 * The album merge's lyrics statement against the migrated schema, inside the test's transaction
 * as inside a merge's. Lyrics name their song through a foreign key, so the songs exist.
 */
final class AlbumLyricsMergeTest extends TestCase
{
    private const string STORED_AT = '2026-11-02 21:15:00.254321+00';
    private const string KEPT_STORED_AT = '2026-11-08 18:30:00.654321+00';

    private Uuid $kept;
    private Uuid $duplicate;

    protected function setUp(): void
    {
        parent::setUp();
        $library = $this->library();
        $this->kept = $this->song($this->album($library, 'Abbey Road'), 'Come Together');
        $this->duplicate = $this->song($this->album($library, 'Abbey Road (Remaster)'), 'Come Together');
    }

    /**
     * The kept song has no lyrics. Afterwards the duplicate's are the kept song's, with their ID,
     * both texts, source, LRCLIB ID, instrumental flag and times, and the duplicate has none.
     */
    public function testTheDuplicatesLyricsBecomeTheKeptSongsWhenItHasNone(): void
    {
        $lyrics = $this->lyrics($this->duplicate, 'Here come old flat-top', '[00:12.00]Here come old flat-top', 4711, instrumental: true, storedAt: self::STORED_AT);

        $this->fold();

        $this->assertSame(
            [[$lyrics->toString(), $this->kept->toString(), 'Here come old flat-top', '[00:12.00]Here come old flat-top', 'lrclib', 4711, true, self::STORED_AT, self::STORED_AT]],
            $this->lyricsRows(),
        );
    }

    /** Both songs have lyrics: the kept song's stay as they were, and the duplicate's go. */
    public function testWhenBothHaveLyricsTheKeptSongsStay(): void
    {
        $this->lyrics($this->duplicate, 'Here come old flat-top', null, 4711, instrumental: false, storedAt: self::STORED_AT);
        $kept = $this->lyrics($this->kept, 'Come together, right now', '[01:02.00]Come together, right now', 815, instrumental: false, storedAt: self::KEPT_STORED_AT);

        $this->fold();

        $this->assertSame(
            [[$kept->toString(), $this->kept->toString(), 'Come together, right now', '[01:02.00]Come together, right now', 'lrclib', 815, false, self::KEPT_STORED_AT, self::KEPT_STORED_AT]],
            $this->lyricsRows(),
        );
    }

    /**
     * The manager has loaded the duplicate's lyrics. After the fold the repository finds them as
     * the kept song's, the manager finds none of the duplicate, and a flush leaves the row as the
     * fold wrote it.
     */
    public function testTheLyricsTheManagerHoldsOfTheDuplicateLeaveIt(): void
    {
        $lyrics = $this->lyrics($this->duplicate, 'Here come old flat-top', null, 4711, instrumental: false, storedAt: self::STORED_AT);
        self::assertSame($this->duplicate->toString(), $this->entityManager->find(LyricsEntity::class, $lyrics)?->getSongId()->toString());

        $this->fold();

        $repository = static::getContainer()->get(LyricsRepositoryInterface::class);
        $this->assertSame($lyrics->toString(), $repository->findBySongId($this->kept)?->getId()->toString());
        $this->assertSame($this->kept->toString(), $repository->findBySongId($this->kept)?->getSongId()->toString());
        $this->assertNull($repository->findBySongId($this->duplicate));
        $this->entityManager->flush();
        $this->assertSame([$this->kept->toString()], $this->entityManager->getConnection()->fetchFirstColumn('SELECT song_id FROM lyrics WHERE id = ?', [$lyrics->toString()]));
    }

    private function fold(): void
    {
        new AlbumLyricsMerge($this->entityManager)->foldSong($this->duplicate, $this->kept);
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

    /** Stores lyrics of the song from LRCLIB, as a fetch does, stored and last updated at $storedAt. */
    private function lyrics(Uuid $song, string $plain, ?string $synced, int $lrclibId, bool $instrumental, string $storedAt): Uuid
    {
        $id = Uuid::v7();
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO lyrics (id, song_id, plain_lyrics, synced_lyrics, source, lrclib_id, is_instrumental, created_at, updated_at) VALUES (?, ?, ?, ?, 'lrclib', ?, ?, ?, ?)",
            [$id->toString(), $song->toString(), $plain, $synced, $lrclibId, $instrumental ? 'true' : 'false', $storedAt, $storedAt],
        );

        return $id;
    }

    /**
     * @return list<array{string, string, ?string, ?string, string, ?int, bool, string, string}> ID, song, both texts, source, LRCLIB ID,
     *                                                                                           instrumental flag and times of the lyrics of both songs
     */
    private function lyricsRows(): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row[0], (string) $row[1], $row[2] === null ? null : (string) $row[2], $row[3] === null ? null : (string) $row[3], (string) $row[4], $row[5] === null ? null : (int) $row[5], (bool) $row[6], (string) $row[7], (string) $row[8]],
            $this->entityManager->getConnection()->fetchAllNumeric(
                'SELECT id, song_id, plain_lyrics, synced_lyrics, source, lrclib_id, is_instrumental, created_at, updated_at FROM lyrics WHERE song_id IN (?, ?) ORDER BY id',
                [$this->kept->toString(), $this->duplicate->toString()],
            ),
        );
    }
}
