<?php

declare(strict_types=1);

namespace App\Transcode\Domain\Service;

use App\Transcode\Domain\ValueObject\QualityTier;

final class QualityLadder
{
    /**
     * @return QualityTier[]
     */
    public static function defaultTiers(): array
    {
        return [
            QualityTier::p360(),
            QualityTier::p480(),
            QualityTier::p720(),
            QualityTier::p1080(),
            QualityTier::p1440(),
            QualityTier::p4K(),
        ];
    }

    /**
     * Find the closest quality tier for a given resolution height.
     */
    public static function tierForResolution(int $height): ?QualityTier
    {
        $tiers = self::defaultTiers();
        $best = null;
        $bestDiff = PHP_INT_MAX;

        foreach ($tiers as $tier) {
            $diff = abs($tier->height - $height);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $tier;
            }
        }

        return $best;
    }

    /**
     * Generate RFC 6381 codec string for a quality tier with audio codec.
     */
    public static function rfc6381CodecString(QualityTier $tier, string $audioCodec = 'mp4a.40.2'): string
    {
        return sprintf('%s,%s', $tier->rfc6381Codec, $audioCodec);
    }
}
