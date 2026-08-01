<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics\Domain\Model;

use App\Lyrics\Domain\Model\Lyrics;
use App\Lyrics\Domain\Model\LyricsState;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LyricsTest extends TestCase
{
    private Uuid $songId;

    protected function setUp(): void
    {
        $this->songId = Uuid::v4();
    }

    // ── Creation ──────────────────────────────────────────────────────

    public function testCreateWithPlainLyricsOnly(): void
    {
        $lyrics = Lyrics::create(
            $this->songId,
            'Hello world',
            'lrclib',
        );

        $this->assertSame($this->songId, $lyrics->getSongId());
        $this->assertSame('Hello world', $lyrics->getLyrics());
        $this->assertSame('lrclib', $lyrics->getSource());
        $this->assertNull($lyrics->getSourceUrl());
        $this->assertFalse($lyrics->isInstrumental());
        $this->assertNull($lyrics->getSyncedLyrics());
        $this->assertNull($lyrics->getLrclibId());
        $this->assertFalse($lyrics->isEmpty());
    }

    public function testCreateWithPlainAndSyncedLyrics(): void
    {
        $lyrics = Lyrics::create(
            $this->songId,
            'Hello world',
            'lrclib',
            syncedLyrics: '[00:01.00] Hello world',
        );

        $this->assertSame('Hello world', $lyrics->getLyrics());
        $this->assertSame('[00:01.00] Hello world', $lyrics->getSyncedLyrics());
    }

    public function testCreateWithAllFields(): void
    {
        $lyrics = Lyrics::create(
            $this->songId,
            'Hello world',
            'lrclib',
            sourceUrl: 'https://lrclib.net/api/get/123',
            isInstrumental: false,
            syncedLyrics: '[00:01.00] Hello world',
            lrclibId: 123,
        );

        $this->assertSame('https://lrclib.net/api/get/123', $lyrics->getSourceUrl());
        $this->assertSame(123, $lyrics->getLrclibId());
    }

    public function testCreateInstrumentalAllowsEmptyLyrics(): void
    {
        $lyrics = Lyrics::create(
            $this->songId,
            '',
            'lrclib',
            isInstrumental: true,
        );

        $this->assertTrue($lyrics->isInstrumental());
        $this->assertTrue($lyrics->isEmpty());
        $this->assertSame('', $lyrics->getLyrics());
    }

    public function testCreateThrowsOnEmptyLyricsForNonInstrumental(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Lyrics cannot be empty for non-instrumental tracks.');

        Lyrics::create($this->songId, '', 'lrclib');
    }

    public function testCreateThrowsOnWhitespaceOnlyLyricsForNonInstrumental(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Lyrics::create($this->songId, '   ', 'lrclib');
    }

    public function testCreateThrowsOnInvalidSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid source: spotify');

        Lyrics::create($this->songId, 'Hello', 'spotify');
    }

    public function testCreateThrowsOnInvalidSourceUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Source URL must be a valid URL.');

        Lyrics::create($this->songId, 'Hello', 'lrclib', sourceUrl: 'not-a-url');
    }

    public function testCreateAcceptsAllValidSources(): void
    {
        foreach (['embedded', 'lrclib', 'musixmatch', 'genius'] as $source) {
            $lyrics = Lyrics::create($this->songId, "Lyrics from {$source}", $source);
            $this->assertSame($source, $lyrics->getSource());
        }
    }

    public function testCreateSetsTimestamps(): void
    {
        $lyrics = Lyrics::create($this->songId, 'Hello', 'lrclib');

        $this->assertInstanceOf(DateTimeImmutable::class, $lyrics->getCreatedAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $lyrics->getUpdatedAt());
    }

    // ── Reconstitute ─────────────────────────────────────────────────

    public function testReconstituteFromState(): void
    {
        $state = new LyricsState(
            id: Uuid::v4(),
            songId: $this->songId,
            lyrics: 'Hello world',
            syncedLyrics: '[00:01.00] Hello world',
            source: 'lrclib',
            sourceUrl: 'https://lrclib.net/api/get/123',
            lrclibId: 42,
            isInstrumental: false,
            createdAt: new DateTimeImmutable('2025-01-01'),
            updatedAt: new DateTimeImmutable('2025-01-02'),
        );

        $lyrics = Lyrics::reconstitute($state);

        $this->assertSame($state->id, $lyrics->getId());
        $this->assertSame($this->songId, $lyrics->getSongId());
        $this->assertSame('Hello world', $lyrics->getLyrics());
        $this->assertSame('[00:01.00] Hello world', $lyrics->getSyncedLyrics());
        $this->assertSame('lrclib', $lyrics->getSource());
        $this->assertSame('https://lrclib.net/api/get/123', $lyrics->getSourceUrl());
        $this->assertSame(42, $lyrics->getLrclibId());
        $this->assertFalse($lyrics->isInstrumental());
        $this->assertEquals(new DateTimeImmutable('2025-01-01'), $lyrics->getCreatedAt());
        $this->assertEquals(new DateTimeImmutable('2025-01-02'), $lyrics->getUpdatedAt());
    }

    public function testGetStateReturnsState(): void
    {
        $lyrics = Lyrics::create($this->songId, 'Hello', 'lrclib');
        $state = $lyrics->getState();

        $this->assertInstanceOf(LyricsState::class, $state);
        $this->assertSame($this->songId, $state->songId);
    }

    // ── Update lyrics ────────────────────────────────────────────────

    public function testUpdateLyricsUpdatesPlainAndSynced(): void
    {
        $lyrics = Lyrics::create($this->songId, 'Hello', 'lrclib');

        $lyrics->updateLyrics('New lyrics', '[00:01.00] New lyrics');

        $this->assertSame('New lyrics', $lyrics->getLyrics());
        $this->assertSame('[00:01.00] New lyrics', $lyrics->getSyncedLyrics());
        // updatedAt changed because content changed
        $state = $lyrics->getState();
        $this->assertNotSame($lyrics->getCreatedAt(), $lyrics->getUpdatedAt());
    }

    public function testUpdateLyricsOnlyPlain(): void
    {
        $lyrics = Lyrics::create($this->songId, 'Hello', 'lrclib');

        $lyrics->updateLyrics('New lyrics');

        $this->assertSame('New lyrics', $lyrics->getLyrics());
        $this->assertNull($lyrics->getSyncedLyrics());
    }

    public function testUpdateLyricsDoesNotUpdateWhenSameContent(): void
    {
        $lyrics = Lyrics::create($this->songId, 'Hello', 'lrclib');
        $updatedAtBefore = $lyrics->getUpdatedAt();

        $lyrics->updateLyrics('Hello');

        // No change in content, updatedAt should be the same object
        $this->assertSame($updatedAtBefore, $lyrics->getUpdatedAt());
    }

    // ── Update source ────────────────────────────────────────────────

    public function testUpdateSource(): void
    {
        $lyrics = Lyrics::create($this->songId, 'Hello', 'embedded');

        $lyrics->updateSource('lrclib', 'https://lrclib.net/123', true);

        $this->assertSame('lrclib', $lyrics->getSource());
        $this->assertSame('https://lrclib.net/123', $lyrics->getSourceUrl());
        $this->assertTrue($lyrics->isInstrumental());
    }

    public function testUpdateSourceDoesNotUpdateWhenSameValues(): void
    {
        $lyrics = Lyrics::create($this->songId, 'Hello', 'lrclib');
        $originalUpdatedAt = $lyrics->getUpdatedAt();

        $lyrics->updateSource('lrclib');

        $this->assertSame($originalUpdatedAt, $lyrics->getUpdatedAt());
    }

    // ── isEmpty ──────────────────────────────────────────────────────

    public function testIsEmptyReturnsFalseForNonEmptyLyrics(): void
    {
        $lyrics = Lyrics::create($this->songId, 'Hello', 'lrclib');
        $this->assertFalse($lyrics->isEmpty());
    }

    public function testIsEmptyReturnsTrueForWhitespaceOnly(): void
    {
        $lyrics = Lyrics::create(
            $this->songId,
            '   ',
            'lrclib',
            isInstrumental: true,
        );
        $this->assertTrue($lyrics->isEmpty());
    }

    // ── getFormattedLyrics ───────────────────────────────────────────

    public function testGetFormattedLyricsForInstrumental(): void
    {
        $lyrics = Lyrics::create(
            $this->songId,
            '',
            'lrclib',
            isInstrumental: true,
        );

        $this->assertSame('[Instrumental]', $lyrics->getFormattedLyrics());
    }

    public function testGetFormattedLyricsForEmpty(): void
    {
        $lyrics = Lyrics::create(
            $this->songId,
            '',
            'lrclib',
            isInstrumental: true,
        );

        // Instrumental takes precedence
        $this->assertSame('[Instrumental]', $lyrics->getFormattedLyrics());
    }

    public function testGetFormattedLyricsWithContent(): void
    {
        $lyrics = Lyrics::create(
            $this->songId,
            "Hello world\nSecond line",
            'lrclib',
        );

        $result = $lyrics->getFormattedLyrics();
        $this->assertStringContainsString('Hello world', $result);
        $this->assertStringContainsString('<br />', $result);
    }
}
