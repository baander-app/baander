<?php

declare(strict_types=1);

namespace App\Metadata\Application\Port;

interface ImdbPersonLookupInterface
{
    /** Returns the single TMDB person identified by the IMDb name ID, or null when unresolved. */
    public function findTmdbPersonId(string $imdbNameId): ?string;
}
