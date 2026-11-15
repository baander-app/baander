<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzReleaseSummary;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** What an album's release search found, and the title that found it, which its candidates are scored against. */
#[Exclude]
final readonly class ReleaseSearchHits
{
    /**
     * @param string                          $title the title searched last
     * @param list<MusicBrainzReleaseSummary> $hits  in MusicBrainz's order
     */
    public function __construct(
        public string $title,
        public array $hits,
    ) {
    }
}
