<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

/**
 * An administrator registers a device, public or confidential OAuth client.
 *
 * The handler answers with a RegisteredClientDTO; a confidential client's
 * secret appears there and nowhere else.
 */
final readonly class RegisterClientCommand
{
    /**
     * @param string $type device, public or confidential (ClientType values)
     * @param list<string> $redirectUris Required for public and confidential clients; must be empty for device clients
     */
    public function __construct(
        public string $name,
        public string $type,
        public array $redirectUris = [],
    ) {
    }
}
