<?php

declare(strict_types=1);

namespace App\Metadata\Application\Port;

use App\Metadata\Application\DTO\ImdbPersonLookupDisabled;

interface ImdbPersonLookupInterface
{
    /** Returns the single TMDB person identified by the IMDb name ID, or null when unresolved. A disabled provider is reported separately. */
    public function findTmdbPersonId(string $imdbNameId): string|ImdbPersonLookupDisabled|null;
}
