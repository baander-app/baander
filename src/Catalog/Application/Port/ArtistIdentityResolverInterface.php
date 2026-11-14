<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * Finds or creates the artists an import credits, so imports running at the same time never
 * create two artists for one MusicBrainz ID or, without an ID, for one name.
 */
interface ArtistIdentityResolverInterface
{
    /**
     * Resolves the identities inside the caller's transaction. It locks every tagged name and ID
     * until that transaction ends, reads the artists they may name as committed after the locks,
     * and resolves the identities in the order given, so the first name seen for a new artist
     * becomes its name.
     *
     * The artists it creates, and the IDs artists without one take on, are flushed before it
     * returns. That flush writes every pending change of the unit of work, so call it before
     * persisting the songs and credits that use the result.
     *
     * Call it once per transaction, with every artist identity the transaction writes, such as a
     * batch's song artists and its album artist together. Its locks are taken in one sorted pass;
     * a second call could take a key that sorts before one already held and deadlock with a
     * transaction taking both in order. Other import identity keys, such as genres, sort after
     * artist keys and may be locked after this call.
     *
     * @param list<ArtistIdentity> $identities
     *
     * @return array<string, Uuid> the artist of each identity, keyed by {@see ArtistIdentity::key()}
     *
     * @throws \LogicException outside a transaction, before locking or writing anything
     */
    public function resolve(array $identities): array;
}
