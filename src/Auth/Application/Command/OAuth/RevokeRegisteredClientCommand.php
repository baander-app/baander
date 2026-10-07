<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

use App\Shared\Domain\Model\PublicId;

/**
 * An administrator revokes a device, public or confidential client together with its tokens.
 */
final readonly class RevokeRegisteredClientCommand
{
    public function __construct(
        public PublicId $clientId,
    ) {
    }
}
