<?php

declare(strict_types=1);

namespace App\Lyrics\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class Lyrics
{
    private function __construct(
        private LyricsState $state,
    ) {
        $this->validate();
    }

    /**
     * Create new lyrics for a song.
     */
    public static function create(
        Uuid $songId,
        string $lyrics,
        string $source,
        ?string $sourceUrl = null,
        bool $isInstrumental = false,
        ?string $syncedLyrics = null,
        ?int $lrclibId = null,
    ): self {
        return new self(new LyricsState(
            id: new Uuid(),
            songId: $songId,
            lyrics: $lyrics,
            syncedLyrics: $syncedLyrics,
            source: $source,
            sourceUrl: $sourceUrl,
            lrclibId: $lrclibId,
            isInstrumental: $isInstrumental,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        ));
    }

    /**
     * Reconstitute Lyrics from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(LyricsState $state): self
    {
        return new self($state);
    }

    /**
     * Update the lyrics content.
     */
    public function updateLyrics(string $lyrics, ?string $syncedLyrics = null): void
    {
        $changed = false;

        if ($lyrics !== $this->state->lyrics) {
            $this->state->lyrics = $lyrics;
            $changed = true;
        }

        if ($syncedLyrics !== null && $syncedLyrics !== $this->state->syncedLyrics) {
            $this->state->syncedLyrics = $syncedLyrics;
            $changed = true;
        }

        if ($changed) {
            $this->state->updatedAt = new DateTimeImmutable();
        }
    }

    /**
     * Update the source information.
     */
    public function updateSource(
        string $source,
        ?string $sourceUrl = null,
        ?bool $isInstrumental = null,
    ): void {
        if ($source !== $this->state->source) {
            $this->state->source = $source;
            $this->state->updatedAt = new DateTimeImmutable();
        }

        if ($sourceUrl !== null && $sourceUrl !== $this->state->sourceUrl) {
            $this->state->sourceUrl = $sourceUrl;
            $this->state->updatedAt = new DateTimeImmutable();
        }

        if ($isInstrumental !== null && $isInstrumental !== $this->state->isInstrumental) {
            $this->state->isInstrumental = $isInstrumental;
            $this->state->updatedAt = new DateTimeImmutable();
        }
    }

    /**
     * Check if lyrics are empty.
     */
    public function isEmpty(): bool
    {
        return trim($this->state->lyrics) === '';
    }

    /**
     * Check if lyrics are instrumental.
     */
    public function isInstrumental(): bool
    {
        return $this->state->isInstrumental;
    }

    /**
     * Get formatted lyrics for display.
     */
    public function getFormattedLyrics(): string
    {
        if ($this->state->isInstrumental) {
            return '[Instrumental]';
        }

        if ($this->isEmpty()) {
            return '[No lyrics available]';
        }

        return nl2br(htmlspecialchars($this->state->lyrics));
    }

    private function validate(): void
    {
        if (trim($this->state->lyrics) === '' && !$this->state->isInstrumental) {
            throw new InvalidArgumentException('Lyrics cannot be empty for non-instrumental tracks.');
        }

        if (!in_array($this->state->source, ['embedded', 'lrclib', 'musixmatch', 'genius'], true)) {
            throw new InvalidArgumentException("Invalid source: {$this->state->source}");
        }

        if ($this->state->sourceUrl !== null && filter_var($this->state->sourceUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Source URL must be a valid URL.');
        }
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getSongId(): Uuid
    {
        return $this->state->songId;
    }

    public function getLyrics(): string
    {
        return $this->state->lyrics;
    }

    public function getSyncedLyrics(): ?string
    {
        return $this->state->syncedLyrics;
    }

    public function getSource(): string
    {
        return $this->state->source;
    }

    public function getSourceUrl(): ?string
    {
        return $this->state->sourceUrl;
    }

    public function getLrclibId(): ?int
    {
        return $this->state->lrclibId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getState(): LyricsState
    {
        return $this->state;
    }
}
