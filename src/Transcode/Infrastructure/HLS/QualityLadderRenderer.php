<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\HLS;

use App\Transcode\Domain\Service\QualityLadder;
use App\Transcode\Domain\ValueObject\QualityTier;

final class QualityLadderRenderer
{
    /**
     * Render available quality tiers as a list suitable for API responses.
     *
     * @param list<QualityTier> $availableTiers Tiers that have completed segments
     * @return list<array{name: string, height: int, width: int, bitrate: int, codec: string}>
     */
    public function renderAvailableTiers(array $availableTiers): array
    {
        $tiers = [];

        foreach ($availableTiers as $tier) {
            $tiers[] = [
                'name' => $tier->name,
                'height' => $tier->height,
                'width' => $tier->width,
                'bitrate' => $tier->videoBitrate,
                'codec' => $tier->rfc6381Codec,
            ];
        }

        return $tiers;
    }

    /**
     * Get tiers that should be included based on source video resolution.
     * Only tiers at or below the source resolution are offered.
     */
    public function tiersForResolution(int $sourceHeight): array
    {
        $allTiers = QualityLadder::defaultTiers();
        $eligible = [];

        foreach ($allTiers as $tier) {
            if ($tier->height <= $sourceHeight) {
                $eligible[] = $tier;
            }
        }

        // Always include at least the lowest tier
        if (empty($eligible)) {
            $eligible[] = QualityTier::p360();
        }

        return $eligible;
    }
}
