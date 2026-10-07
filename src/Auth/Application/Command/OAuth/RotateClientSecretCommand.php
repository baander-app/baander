<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

use App\Shared\Domain\Model\PublicId;

/**
 * An administrator replaces a confidential client's secret; the old one stops working at once.
 */
final readonly class RotateClientSecretCommand
{
    public function __construct(
        public PublicId $clientId,
    ) {
    }
}
