<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Application\Exception\OAuthClientRateLimitedException;
use App\Auth\Domain\Model\OAuth\Client;

/**
 * Limits the token and pushed authorization requests of one client.
 *
 * Handlers charge a request only after the client has authenticated, so a caller who
 * merely names a client cannot drain its allowance. A client that authenticates with
 * a secret or a key is charged under its own ID. A public client has no credential to
 * prove, so it is charged per client ID and caller network.
 */
interface OAuthClientThrottleInterface
{
    /** @throws OAuthClientRateLimitedException when the client has used up its allowance */
    public function chargeTokenRequest(Client $client, ?string $ipAddress): void;

    /** @throws OAuthClientRateLimitedException when the client has used up its allowance */
    public function chargePushedRequest(Client $client, ?string $ipAddress): void;
}
