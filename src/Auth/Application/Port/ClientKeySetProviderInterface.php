<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Auth\Domain\Model\OAuth\Client;

/**
 * Serves a `private_key_jwt` client's public signing keys from its registered key source.
 */
interface ClientKeySetProviderInterface
{
    /**
     * The keys an assertion of this client may be signed with: with a `kid`, the keys
     * carrying that `kid`; without one, every signing key. Keys registered only for
     * encryption are left out.
     *
     * @return list<array<string, mixed>> Public JWKs; empty when the client has none to offer
     *
     * @throws DpopStoreUnavailableException when a published key set cannot be fetched now,
     *                                       or Redis cannot coordinate the fetch
     */
    public function signingKeys(Client $client, ?string $kid): array;
}
