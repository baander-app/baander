<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class TvSeries
{
    private function __construct(private TvSeriesState $state) {}

    public static function create(Uuid $libraryId, string $folderPath, string $name, ?int $year = null): self
    {
        self::assertName($name);
        if (trim($folderPath) === '') {
            throw new InvalidArgumentException('TV series folder cannot be empty.');
        }

        return new self(new TvSeriesState(
            id: Uuid::v7(),
            publicId: new PublicId(),
            libraryId: $libraryId,
            folderPath: $folderPath,
            name: trim($name),
            year: $year,
        ));
    }

    public static function reconstitute(TvSeriesState $state): self
    {
        return new self($state);
    }

    public function relocate(string $folderPath): void
    {
        if (trim($folderPath) === '') {
            throw new InvalidArgumentException('TV series folder cannot be empty.');
        }
        $this->state->folderPath = $folderPath;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /** @param array<string, string> $externalIds */
    public function match(string $provider, string $providerId, array $externalIds, bool $handMatched = false): void
    {
        if (trim($provider) === '' || trim($providerId) === '') {
            throw new InvalidArgumentException('A TV match requires a provider and its entry ID.');
        }
        $this->state->matchedProvider = $provider;
        $this->state->providerId = $providerId;
        $this->state->externalIds = $externalIds;
        $this->state->handMatched = $handMatched;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /** @param list<string> $offeredOrderings */
    public function setOrdering(string $ordering, array $offeredOrderings): void
    {
        if (trim($ordering) === '' || !in_array($ordering, $offeredOrderings, true)) {
            throw new InvalidArgumentException('The matched series does not offer that episode ordering.');
        }
        $this->state->ordering = $ordering;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function updateMetadata(
        string $name,
        ?string $overview,
        ?string $posterUrl,
        ?string $backdropUrl = null,
        ?int $year = null,
    ): void {
        self::assertName($name);
        $this->state->name = trim($name);
        $this->state->overview = $overview;
        $this->state->posterUrl = $posterUrl;
        $this->state->backdropUrl = $backdropUrl;
        $this->state->year = $year;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    private static function assertName(string $name): void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('TV series name cannot be empty.');
        }
    }

    public function getId(): Uuid { return $this->state->id; }
    public function getPublicId(): PublicId { return $this->state->publicId; }
    public function getLibraryId(): Uuid { return $this->state->libraryId; }
    public function getFolderPath(): string { return $this->state->folderPath; }
    public function getName(): string { return $this->state->name; }
    public function getYear(): ?int { return $this->state->year; }
    public function getMatchedProvider(): ?string { return $this->state->matchedProvider; }
    public function getProviderId(): ?string { return $this->state->providerId; }
    /** @return array<string, string> */
    public function getExternalIds(): array { return $this->state->externalIds; }
    public function getOrdering(): string { return $this->state->ordering; }
    public function isHandMatched(): bool { return $this->state->handMatched; }
    public function getState(): TvSeriesState { return $this->state; }
}
