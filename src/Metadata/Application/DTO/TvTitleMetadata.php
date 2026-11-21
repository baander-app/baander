<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO;

use App\Catalog\Application\Port\PersonCreditInput;

final readonly class TvTitleMetadata
{
    /**
     * @param list<PersonCreditInput>|null $cast
     * @param list<PersonCreditInput>|null $crew
     */
    public function __construct(
        public int $tmdbId,
        public string $name,
        public ?string $overview,
        public ?string $imageUrl,
        public ?array $cast,
        public ?array $crew,
        public ?string $airDate = null,
    ) {}
}
