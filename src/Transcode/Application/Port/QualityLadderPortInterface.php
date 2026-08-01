<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

/**
 * Read access to the transcode quality ladder as primitives.
 *
 * Implemented by Transcode Infrastructure so that other contexts' Domain
 * layers can query the ladder without importing the Transcode Domain.
 */
interface QualityLadderPortInterface
{
    /**
     * Default quality tier names, ascending (e.g. ['360p','480p','720p','1080p','1440p','4K']).
     *
     * @return list<string>
     */
    public function defaultTierNames(): array;

    /**
     * Default quality tiers as primitives, ascending.
     *
     * @return list<array{name: string, videoBitrate: int}>
     */
    public function defaultTiers(): array;
}
