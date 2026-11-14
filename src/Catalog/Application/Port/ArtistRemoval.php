<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * What an artist merge or removal leaves for after its transaction: the cover images no artist
 * uses any more. Deleting them earlier would leave the artist pointing at a deleted image when the
 * transaction rolls back.
 */
final readonly class ArtistRemoval
{
    /** @param list<Uuid> $imagesToDiscard */
    public function __construct(
        public array $imagesToDiscard = [],
    ) {
    }
}
