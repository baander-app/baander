<?php

declare(strict_types=1);

namespace App\Metadata\Application\Port;

interface TmdbPersonLookupInterface
{
    public function isConfigured(): bool;

    /** @return array<string, mixed>|null */
    public function lookupPerson(int $tmdbId): ?array;
}
