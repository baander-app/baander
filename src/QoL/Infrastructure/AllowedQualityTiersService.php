<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure;

use App\QoL\Application\Port\AllowedQualityTiersPortInterface;
use App\QoL\Domain\Service\StreamGovernor;

/** Reads the allowed tiers from the in-memory StreamGovernor singleton. */
final readonly class AllowedQualityTiersService implements AllowedQualityTiersPortInterface
{
    public function __construct(private StreamGovernor $governor)
    {
    }

    public function allowedTierNames(): array
    {
        return $this->governor->getAllowedTiers();
    }
}
