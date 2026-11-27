<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class TvEpisode
{
    private function __construct(private TvEpisodeState $state) {}

    public static function create(
        Uuid $seriesId,
        ?Uuid $seasonId,
        ?int $episodeNumber,
        string $name,
        ?int $absoluteNumber = null,
        ?DateTimeImmutable $airDate = null,
    ): self {
        self::assertNumbering($seasonId, $episodeNumber, $absoluteNumber);
        self::assertName($name);

        return new self(new TvEpisodeState(
            id: Uuid::v7(),
            publicId: new PublicId(),
            seriesId: $seriesId,
            seasonId: $seasonId,
            episodeNumber: $episodeNumber,
            name: trim($name),
            absoluteNumber: $absoluteNumber,
            airDate: $airDate,
        ));
    }

    public static function reconstitute(TvEpisodeState $state): self
    {
        return new self($state);
    }

    public function place(?Uuid $seasonId, ?int $episodeNumber): void
    {
        self::assertNumbering($seasonId, $episodeNumber, $this->state->absoluteNumber);
        $this->state->seasonId = $seasonId;
        $this->state->episodeNumber = $episodeNumber;
        $this->state->retired = false;
    }

    public function retire(): void
    {
        $this->state->seasonId = null;
        $this->state->episodeNumber = null;
        $this->state->retired = true;
    }

    /** @param array<string, string> $externalIds */
    public function match(string $providerId, array $externalIds = []): void
    {
        if (trim($providerId) === '') {
            throw new InvalidArgumentException('A TV episode match requires a provider entry ID.');
        }
        $this->state->providerId = $providerId;
        $this->state->externalIds = $externalIds;
    }

    public function updateMetadata(
        string $name,
        ?string $overview,
        ?string $stillUrl,
        ?DateTimeImmutable $airDate,
        ?int $absoluteNumber = null,
    ): void {
        self::assertName($name);
        self::assertNumbering($this->state->seasonId, $this->state->episodeNumber, $absoluteNumber);
        $this->state->name = trim($name);
        $this->state->overview = $overview;
        $this->state->stillUrl = $stillUrl;
        $this->state->airDate = $airDate;
        $this->state->absoluteNumber = $absoluteNumber;
    }

    private static function assertNumbering(?Uuid $seasonId, ?int $episodeNumber, ?int $absoluteNumber): void
    {
        if (($seasonId === null) !== ($episodeNumber === null) || ($episodeNumber !== null && $episodeNumber < 1)) {
            throw new InvalidArgumentException('TV episode numbering requires a season and a positive episode number together.');
        }
        if ($absoluteNumber !== null && $absoluteNumber < 1) {
            throw new InvalidArgumentException('Absolute episode number must be positive.');
        }
    }

    private static function assertName(string $name): void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('TV episode name cannot be empty.');
        }
    }

    public function getId(): Uuid { return $this->state->id; }
    public function getPublicId(): PublicId { return $this->state->publicId; }
    public function getSeriesId(): Uuid { return $this->state->seriesId; }
    public function getSeasonId(): ?Uuid { return $this->state->seasonId; }
    public function getEpisodeNumber(): ?int { return $this->state->episodeNumber; }
    public function getName(): string { return $this->state->name; }
    public function getAbsoluteNumber(): ?int { return $this->state->absoluteNumber; }
    public function getAirDate(): ?DateTimeImmutable { return $this->state->airDate; }
    public function getProviderId(): ?string { return $this->state->providerId; }
    public function isRetired(): bool { return $this->state->retired; }
    public function getState(): TvEpisodeState { return $this->state; }
}
