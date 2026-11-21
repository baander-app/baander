<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

interface PersonIdentityPortInterface
{
    /**
     * @param array<string, string> $ids
     * @param array<string, mixed> $profile
     */
    public function enrichTmdbPerson(string $tmdbId, array $ids, array $profile): void;

    /**
     * Atomically claims at most five pending IDs for five minutes, ordered by external ID.
     * Completed enrichment clears the claim. Interrupted work becomes eligible after expiry.
     *
     * @return list<string>
     */
    public function pendingTmdbPeople(int $limit, ?string $after = null): array;
}
