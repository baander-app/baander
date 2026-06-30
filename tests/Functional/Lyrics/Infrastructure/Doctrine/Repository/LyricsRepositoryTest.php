<?php

declare(strict_types=1);

namespace App\Tests\Functional\Lyrics\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\ValueObject\AlbumType;
use App\Lyrics\Domain\Model\Lyrics;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;

final class LyricsRepositoryTest extends TestCase
{
    private LyricsRepositoryInterface $lyricsRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->lyricsRepository = $container->get(LyricsRepositoryInterface::class);
    }

    /**
     * Creates a minimal album + song via raw SQL to satisfy FK constraints.
     * Returns the song UUID.
     */
    private function createSongFixture(): Uuid
    {
        $libraryId = Uuid::v7();
        $albumId = Uuid::v7();
        $songId = Uuid::v7();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $conn = $this->entityManager->getConnection();

        $conn->executeStatement(
            'INSERT INTO libraries (id, slug, name, path, type, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $libraryId->toString(),
                'test-lib-' . bin2hex(random_bytes(4)),
                'Test Library',
                '/tmp/test-' . bin2hex(random_bytes(4)),
                'music',
                0,
                $now,
                $now,
            ],
        );

        $conn->executeStatement(
            'INSERT INTO albums (id, public_id, library_id, title, type, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $albumId->toString(),
                'test-album-' . bin2hex(random_bytes(4)),
                $libraryId->toString(),
                'Test Album',
                'studio',
                '{}',
                $now,
                $now,
            ],
        );

        $conn->executeStatement(
            'INSERT INTO songs (id, public_id, album_id, title, path, size, mime_type, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $songId->toString(),
                'test-song-' . bin2hex(random_bytes(4)),
                $albumId->toString(),
                'Test Song',
                '/tmp/test-song.mp3',
                1000,
                'audio/mpeg',
                '{}',
                $now,
                $now,
            ],
        );

        // Clear EM cache so it picks up the raw-inserted entities
        $this->entityManager->clear();

        return $songId;
    }

    // --- Happy path: save and findBySongId ---

    public function testSaveCreatesNewLyrics(): void
    {
        $songId = $this->createSongFixture();
        $lyrics = Lyrics::create(
            songId: $songId,
            lyrics: 'Some lyrics text',
            source: 'lrclib',
            sourceUrl: 'https://lrclib.net/api/get/123',
            syncedLyrics: '[00:17.12] Some lyrics text',
            lrclibId: 123,
        );

        $this->lyricsRepository->save($lyrics);

        $found = $this->lyricsRepository->findBySongId($songId);

        $this->assertNotNull($found);
        $this->assertSame($songId->toString(), $found->getSongId()->toString());
        $this->assertSame('Some lyrics text', $found->getLyrics());
        $this->assertSame('[00:17.12] Some lyrics text', $found->getSyncedLyrics());
        $this->assertSame('lrclib', $found->getSource());
        $this->assertSame('https://lrclib.net/api/get/123', $found->getSourceUrl());
        $this->assertSame(123, $found->getLrclibId());
        $this->assertFalse($found->isInstrumental());
    }

    // --- Happy path: save instrumental lyrics ---

    public function testSaveInstrumentalLyrics(): void
    {
        $songId = $this->createSongFixture();
        $lyrics = Lyrics::create(
            songId: $songId,
            lyrics: '',
            source: 'lrclib',
            isInstrumental: true,
        );

        $this->lyricsRepository->save($lyrics);

        $found = $this->lyricsRepository->findBySongId($songId);

        $this->assertNotNull($found);
        $this->assertTrue($found->isInstrumental());
        $this->assertSame('', $found->getLyrics());
        $this->assertNull($found->getSyncedLyrics());
    }

    // --- Happy path: save with lrclibId and findByLrclibId ---

    public function testFindByLrclibIdReturnsMatchingLyrics(): void
    {
        $songId = $this->createSongFixture();
        $lyrics = Lyrics::create(
            songId: $songId,
            lyrics: 'Found by lrclib ID',
            source: 'lrclib',
            lrclibId: 99999,
        );

        $this->lyricsRepository->save($lyrics);

        $found = $this->lyricsRepository->findByLrclibId(99999);

        $this->assertNotNull($found);
        $this->assertSame('Found by lrclib ID', $found->getLyrics());
        $this->assertSame(99999, $found->getLrclibId());
    }

    // --- Happy path: update existing lyrics via save ---

    public function testSaveUpdatesExistingLyrics(): void
    {
        $songId = $this->createSongFixture();
        $lyrics = Lyrics::create(
            songId: $songId,
            lyrics: 'Original lyrics',
            source: 'lrclib',
            lrclibId: 42,
        );

        $this->lyricsRepository->save($lyrics);

        // Update via domain model
        $lyrics->updateLyrics('Updated lyrics', '[00:01.00] Updated lyrics');
        $this->lyricsRepository->save($lyrics);

        $found = $this->lyricsRepository->findBySongId($songId);

        $this->assertNotNull($found);
        $this->assertSame('Updated lyrics', $found->getLyrics());
        $this->assertSame('[00:01.00] Updated lyrics', $found->getSyncedLyrics());
    }

    // --- Happy path: two lyrics for different songs ---

    public function testSaveTwoLyricsForDifferentSongs(): void
    {
        $songId1 = $this->createSongFixture();
        $songId2 = $this->createSongFixture();

        $lyrics1 = Lyrics::create(
            songId: $songId1,
            lyrics: 'Song one lyrics',
            source: 'lrclib',
            lrclibId: 100,
        );

        $lyrics2 = Lyrics::create(
            songId: $songId2,
            lyrics: 'Song two lyrics',
            source: 'embedded',
        );

        $this->lyricsRepository->save($lyrics1);
        $this->lyricsRepository->save($lyrics2);

        $found1 = $this->lyricsRepository->findBySongId($songId1);
        $found2 = $this->lyricsRepository->findBySongId($songId2);

        $this->assertNotNull($found1);
        $this->assertNotNull($found2);
        $this->assertSame('Song one lyrics', $found1->getLyrics());
        $this->assertSame('Song two lyrics', $found2->getLyrics());
    }

    // --- Edge case: findBySongId with non-existent song ---

    public function testFindBySongIdReturnsNullForNonExistent(): void
    {
        $result = $this->lyricsRepository->findBySongId(new Uuid());

        $this->assertNull($result);
    }

    // --- Edge case: findByLrclibId with non-existent ID ---

    public function testFindByLrclibIdReturnsNullForNonExistent(): void
    {
        $result = $this->lyricsRepository->findByLrclibId(999999);

        $this->assertNull($result);
    }

    // --- Happy path: delete lyrics ---

    public function testDeleteRemovesLyrics(): void
    {
        $songId = $this->createSongFixture();
        $lyrics = Lyrics::create(
            songId: $songId,
            lyrics: 'To be deleted',
            source: 'lrclib',
        );

        $this->lyricsRepository->save($lyrics);

        $found = $this->lyricsRepository->findBySongId($songId);
        $this->assertNotNull($found);

        $this->lyricsRepository->delete($lyrics);

        $found = $this->lyricsRepository->findBySongId($songId);
        $this->assertNull($found);
    }

    // --- Edge case: delete non-existent lyrics is silent no-op ---

    public function testDeleteNonExistentLyricsIsSilentNoOp(): void
    {
        $lyrics = Lyrics::create(
            songId: new Uuid(),
            lyrics: 'Ghost lyrics',
            source: 'lrclib',
        );

        // Never saved, should not throw
        $this->lyricsRepository->delete($lyrics);

        $this->assertTrue(true);
    }
}
