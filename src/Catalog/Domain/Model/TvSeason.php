<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;

final class TvSeason
{
    private const array METADATA_FIELDS = ['name', 'overview', 'posterUrl'];

    private function __construct(private TvSeasonState $state) {}

    public static function create(Uuid $seriesId, int $seasonNumber): self
    {
        if ($seasonNumber < 0) {
            throw new InvalidArgumentException('TV season number cannot be negative.');
        }

        return new self(new TvSeasonState(Uuid::v7(), new PublicId(), $seriesId, $seasonNumber));
    }

    public static function reconstitute(TvSeasonState $state): self
    {
        return new self($state);
    }

    public function updateMetadata(?string $name, ?string $overview, ?string $posterUrl): void
    {
        $this->state->name = $name;
        $this->state->overview = $overview;
        $this->state->posterUrl = $posterUrl;
        foreach (self::METADATA_FIELDS as $field) {
            $this->lockField($field);
        }
    }

    /** @param array<string, string|int|null> $values */
    public function applyProviderMetadata(array $values, bool $force = false): void
    {
        $current = [
            'name' => $this->state->name,
            'overview' => $this->state->overview,
            'posterUrl' => $this->state->posterUrl,
        ];
        $merged = ProviderMetadataFields::merge($current, $this->state->providerFields, $values, $this->state->lockedFields, $force);
        $fields = $merged['values'];
        $this->state->name = ProviderMetadataFields::text($fields['name']);
        $this->state->overview = ProviderMetadataFields::text($fields['overview']);
        $this->state->posterUrl = ProviderMetadataFields::text($fields['posterUrl']);
        $this->state->providerFields = $merged['providerFields'];
    }

    public function lockField(string $field): void
    {
        LockedFields::assertLockable(self::METADATA_FIELDS, $field, 'lock');
        if (!in_array($field, $this->state->lockedFields, true)) {
            $this->state->lockedFields[] = $field;
        }
    }

    /** @return list<string> */
    public function getLockedFields(): array
    {
        return $this->state->lockedFields;
    }

    public function getId(): Uuid { return $this->state->id; }
    public function getPublicId(): PublicId { return $this->state->publicId; }
    public function getSeriesId(): Uuid { return $this->state->seriesId; }
    public function getSeasonNumber(): int { return $this->state->seasonNumber; }
    public function getPosterUrl(): ?string { return $this->state->posterUrl; }
    public function getState(): TvSeasonState { return $this->state; }
}
