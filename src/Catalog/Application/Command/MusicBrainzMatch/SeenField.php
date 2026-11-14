<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\MusicBrainzMatch;

use App\Catalog\Application\Query\MusicBrainzMatch\MatchField;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** A field row of a comparison the admin ticked, with the current value it showed. */
#[Exclude]
final readonly class SeenField
{
    /**
     * @param string|int|bool|list<string>|null $current      the value the row showed as current; null when empty,
     *                                                       true for a cover the album had
     * @param string|null                  $songPublicId the album's song the row belongs to; null for the item's own field
     */
    public function __construct(
        public MatchField $field,
        public string|int|bool|array|null $current,
        public ?string $songPublicId = null,
    ) {
    }
}
