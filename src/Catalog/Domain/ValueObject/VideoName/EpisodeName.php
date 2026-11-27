<?php

declare(strict_types=1);

namespace App\Catalog\Domain\ValueObject\VideoName;

final readonly class EpisodeName implements \JsonSerializable
{
    /**
     * @param list<ProviderTag> $providerTags
     * @param list<int> $episodeNumbers
     */
    public function __construct(
        public string $seriesFolder,
        public string $title,
        public ?int $year,
        public array $providerTags,
        public ?int $seasonNumber,
        public array $episodeNumbers,
        public ?int $absoluteNumber,
        public ?string $airDate,
        public ?ExtraKind $extraKind,
        public bool $sample,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'seriesFolder' => $this->seriesFolder,
            'title' => $this->title,
            'year' => $this->year,
            'providerTags' => array_map(static fn (ProviderTag $tag): array => $tag->jsonSerialize(), $this->providerTags),
            'seasonNumber' => $this->seasonNumber,
            'episodeNumbers' => $this->episodeNumbers,
            'absoluteNumber' => $this->absoluteNumber,
            'airDate' => $this->airDate,
            'extraKind' => $this->extraKind?->value,
            'sample' => $this->sample,
        ];
    }
}
