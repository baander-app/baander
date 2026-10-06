<?php

declare(strict_types=1);

namespace App\QoL\Application\Port;

/**
 * Quality tiers the stream budget currently allows new streams to use.
 */
interface AllowedQualityTiersPortInterface
{
    /**
     * Allowed tier names, ascending. Every default tier is allowed while QoL is learning.
     *
     * @return list<string>
     */
    public function allowedTierNames(): array;
}
