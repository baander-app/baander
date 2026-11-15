<?php

declare(strict_types=1);

namespace App\Tests\Functional\Playlist\Infrastructure\Doctrine;

use App\Playlist\Application\Exception\PlaylistWriteConflictException;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use App\Playlist\Infrastructure\Doctrine\AlbumPlaylistMerge;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistSongEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;

/**
 * The album merge's playlist statements against the migrated schema, inside the test's
 * transaction as inside a merge's. Entries name their song through a foreign key, so the songs
 * exist.
 */
final class AlbumPlaylistMergeTest extends TestCase
{
    private const string ADDED_AT = '2026-11-02 21:15:00.254321+00';

    private Uuid $owner;
    private Uuid $kept;
    private Uuid $duplicate;
    private Uuid $other;

    protected function setUp(): void
    {
        parent::setUp();
        $library = $this->library();
        $target = $this->album($library, 'Abbey Road');
        $source = $this->album($library, 'Abbey Road (Remaster)');
        $this->owner = $this->createTestUser()->getId();
        $this->kept = $this->song($target, 'Come Together');
        $this->duplicate = $this->song($source, 'Come Together');
        $this->other = $this->song($target, 'Something');
    }

    /**
     * A playlist holds the duplicate and another song, not the kept song. Afterwards it holds the
     * kept song at the duplicate's position, in the duplicate's entry, with its ID and creation
     * time, and its revision has changed. A playlist without the duplicate is left as it was.
     */
    public function testAnEntryOfTheDuplicateBecomesTheKeptSongsAtItsPositionAndThePlaylistsRevisionChanges(): void
    {
        $playlist = $this->playlist([$this->other->toString() => 0, $this->duplicate->toString() => 2]);
        $entry = $this->entryOf($playlist, $this->duplicate);
        $untouched = $this->playlist([$this->other->toString() => 0]);
        $revision = $this->revisionOf($playlist);
        $untouchedRevision = $this->revisionOf($untouched);

        $this->fold([[$this->duplicate, $this->kept]]);

        $this->assertSame([$this->other->toString() => 0, $this->kept->toString() => 2], $this->entriesOf($playlist));
        $this->assertSame([$entry->toString(), self::ADDED_AT], $this->entryRow($playlist, $this->kept));
        $this->assertNotSame($revision, $this->revisionOf($playlist));
        $this->assertSame([1, 0], [$this->versionOf($playlist), $this->versionOf($untouched)]);
        $this->assertSame($untouchedRevision, $this->revisionOf($untouched));
    }

    /** A playlist that holds both songs keeps one entry, the kept song's, at the position it had. */
    public function testAPlaylistThatHoldsBothKeepsTheKeptSongsEntryAtItsPosition(): void
    {
        $playlist = $this->playlist([$this->duplicate->toString() => 0, $this->kept->toString() => 3]);
        $entry = $this->entryOf($playlist, $this->kept);

        $this->fold([[$this->duplicate, $this->kept]]);

        $this->assertSame([$this->kept->toString() => 3], $this->entriesOf($playlist));
        $this->assertSame([$entry->toString(), self::ADDED_AT], $this->entryRow($playlist, $this->kept));
    }

    /**
     * Two duplicates of one song, folded in the order given. A playlist that holds both, and not
     * the kept song, ends with one entry of the kept song, at the first duplicate's position; one
     * that holds only the second has the kept song in its place.
     */
    public function testTwoDuplicatesOfOneSongFoldIntoItOneAfterTheOther(): void
    {
        $second = $this->song($this->album($this->library(), 'Abbey Road (Mono)'), 'Come Together');
        $both = $this->playlist([$this->duplicate->toString() => 4, $second->toString() => 7]);
        $secondOnly = $this->playlist([$second->toString() => 1]);

        $this->fold([[$this->duplicate, $this->kept], [$second, $this->kept]]);

        $this->assertSame([$this->kept->toString() => 4], $this->entriesOf($both));
        $this->assertSame([$this->kept->toString() => 1], $this->entriesOf($secondOnly));
    }

    /**
     * A save of a playlist that read it before the fold, and saves after it, answers that the
     * playlist has changed, and writes nothing.
     */
    public function testASaveThatReadThePlaylistBeforeTheFoldAnswersThatThePlaylistHasChanged(): void
    {
        $playlistId = $this->playlist([$this->duplicate->toString() => 0]);
        $repository = static::getContainer()->get(PlaylistRepositoryInterface::class);
        $playlist = $repository->findByUuid($playlistId);
        self::assertNotNull($playlist);

        $this->fold([[$this->duplicate, $this->kept]]);
        $playlist->updateMetadata('Renamed', null, false, false);

        try {
            $repository->save($playlist);
            self::fail('The save did not answer a conflict.');
        } catch (PlaylistWriteConflictException $conflict) {
            $this->assertSame('The playlist has changed. Refresh it before trying again.', $conflict->getMessage());
        }
        $this->assertSame('Road trip', $this->entityManager->getConnection()->fetchOne('SELECT name FROM playlists WHERE id = ?', [$playlistId->toString()]));
        $this->assertSame([$this->kept->toString() => 0], $this->entriesOf($playlistId));
    }

    /**
     * The manager has loaded the duplicate's entry, which the fold gives the kept song, the one a
     * playlist that holds both loses, and an entry of another song. After the fold it reads the
     * first as the kept song's and finds none of the second, the third stays loaded, and a flush
     * leaves the rows as the fold wrote them.
     */
    public function testTheEntriesTheManagerHoldsOfTheDuplicateLeaveIt(): void
    {
        $moving = $this->playlist([$this->duplicate->toString() => 2, $this->other->toString() => 0]);
        $both = $this->playlist([$this->duplicate->toString() => 0, $this->kept->toString() => 1]);
        $moved = $this->entityManager->find(PlaylistSongEntity::class, $this->entryOf($moving, $this->duplicate));
        $dropped = $this->entityManager->find(PlaylistSongEntity::class, $this->entryOf($both, $this->duplicate));
        $other = $this->entityManager->find(PlaylistSongEntity::class, $this->entryOf($moving, $this->other));
        self::assertInstanceOf(PlaylistSongEntity::class, $moved);
        self::assertInstanceOf(PlaylistSongEntity::class, $dropped);
        self::assertInstanceOf(PlaylistSongEntity::class, $other);

        $this->fold([[$this->duplicate, $this->kept]]);

        $this->assertSame($this->kept->toString(), $this->entityManager->find(PlaylistSongEntity::class, $moved->getId())?->getSongId()->toString());
        $this->assertNull($this->entityManager->find(PlaylistSongEntity::class, $dropped->getId()));
        $this->assertTrue($this->entityManager->contains($other), 'The entry of another song is still the one the manager holds.');
        $this->entityManager->flush();
        $this->assertSame([$this->other->toString() => 0, $this->kept->toString() => 2], $this->entriesOf($moving));
        $this->assertSame([$this->kept->toString() => 1], $this->entriesOf($both));
    }

    /** @param list<array{Uuid, Uuid}> $duplicates */
    private function fold(array $duplicates): void
    {
        new AlbumPlaylistMerge($this->entityManager)->foldSongs($duplicates);
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

    /**
     * Stores the owner's playlist with these entries, song ID => position, each added at
     * {@see ADDED_AT}, as a save of the playlist commits it.
     *
     * @param array<string, int> $entries
     */
    private function playlist(array $entries): Uuid
    {
        $id = Uuid::v7();
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            "INSERT INTO playlists (id, public_id, user_id, name, created_at, updated_at) VALUES (?, ?, ?, 'Road trip', clock_timestamp(), clock_timestamp())",
            [$id->toString(), (new PublicId())->toString(), $this->owner->toString()],
        );
        foreach ($entries as $song => $position) {
            $connection->executeStatement(
                'INSERT INTO playlist_song (id, playlist_id, song_id, position, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                [Uuid::v7()->toString(), $id->toString(), $song, $position, self::ADDED_AT, self::ADDED_AT],
            );
        }

        return $id;
    }

    private function entryOf(Uuid $playlist, Uuid $song): Uuid
    {
        return Uuid::fromString((string) $this->entityManager->getConnection()->fetchOne(
            'SELECT id FROM playlist_song WHERE playlist_id = ? AND song_id = ?',
            [$playlist->toString(), $song->toString()],
        ));
    }

    /** @return array{string, string} the ID and creation time of the playlist's entry of the song */
    private function entryRow(Uuid $playlist, Uuid $song): array
    {
        $row = $this->entityManager->getConnection()->fetchNumeric(
            'SELECT id, created_at FROM playlist_song WHERE playlist_id = ? AND song_id = ?',
            [$playlist->toString(), $song->toString()],
        );
        self::assertIsArray($row);

        return [(string) $row[0], (string) $row[1]];
    }

    /** @return array<string, int> the position of each song the playlist holds, by song ID */
    private function entriesOf(Uuid $playlist): array
    {
        $entries = [];
        foreach ($this->entityManager->getConnection()->fetchAllNumeric('SELECT song_id, position FROM playlist_song WHERE playlist_id = ? ORDER BY position', [$playlist->toString()]) as [$song, $position]) {
            $entries[(string) $song] = (int) $position;
        }

        return $entries;
    }

    private function versionOf(Uuid $playlist): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT persistence_version FROM playlists WHERE id = ?', [$playlist->toString()]);
    }

    /** The revision a client reads of the playlist as it is stored now. */
    private function revisionOf(Uuid $playlist): string
    {
        $read = static::getContainer()->get(PlaylistRepositoryInterface::class)->findByUuid($playlist);
        self::assertNotNull($read);

        return $read->getRevision();
    }
}
