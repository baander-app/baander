<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\MusicBrainzMatch;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** A credits row of a comparison the admin ticked, with the primary and featured credits it showed. */
#[Exclude]
final readonly class SeenCredits
{
    /**
     * @param string|null      $songPublicId the song the credits belong to; null for the item's own credits
     * @param list<SeenCredit> $current      the primary and featured credits the row showed as current
     */
    public function __construct(
        public ?string $songPublicId,
        public array $current,
    ) {
    }
}
