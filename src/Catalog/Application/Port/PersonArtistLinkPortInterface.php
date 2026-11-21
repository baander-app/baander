<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface PersonArtistLinkPortInterface
{
    public function needsReconciliation(Uuid $artistId, string $mbid, ?string $type): bool;

    /**
     * The caller already holds the Artist row lock. The MBID must still belong to that Artist.
     *
     * @param array<string, string> $ids
     * @param array<string, mixed> $profile
     */
    public function reconcile(Uuid $artistId, string $mbid, array $ids, array $profile): void;
}
