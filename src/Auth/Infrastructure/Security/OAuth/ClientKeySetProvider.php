<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Port\ClientKeySetProviderInterface;
use App\Auth\Domain\Model\OAuth\Client;

/**
 * Serves the inline JWK Set stored with a `private_key_jwt` client.
 *
 * A client registered with a `jwks_uri` gets no key here, so its assertions fail
 * closed until fetched key sets are served.
 */
final readonly class ClientKeySetProvider implements ClientKeySetProviderInterface
{
    public function signingKeys(Client $client, ?string $kid): array
    {
        $keys = $client->getKeySource()?->jwks()['keys'] ?? [];

        return array_values(array_filter(
            $keys,
            static fn (array $key): bool => ($key['use'] ?? 'sig') === 'sig'
                && ($kid === null || ($key['kid'] ?? null) === $kid),
        ));
    }
}
