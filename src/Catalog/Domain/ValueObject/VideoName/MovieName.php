<?php

declare(strict_types=1);

namespace App\Catalog\Domain\ValueObject\VideoName;

final readonly class MovieName implements \JsonSerializable
{
    /** @param list<ProviderTag> $providerTags */
    public function __construct(
        public string $folderPath,
        public string $title,
        public ?int $year,
        public array $providerTags,
        public ?ExtraKind $extraKind,
        public bool $sample,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'folderPath' => $this->folderPath,
            'title' => $this->title,
            'year' => $this->year,
            'providerTags' => array_map(static fn (ProviderTag $tag): array => $tag->jsonSerialize(), $this->providerTags),
            'extraKind' => $this->extraKind?->value,
            'sample' => $this->sample,
        ];
    }
}
