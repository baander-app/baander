<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Transcode;

use App\QoL\Domain\Port\QualityLadderPortInterface;
use App\Transcode\Domain\Service\QualityLadder;

/**
 * Primitives-only adapter over the Transcode Domain quality ladder, implementing
 * QoL's quality-ladder contract.
 */
final class QualityLadderPort implements QualityLadderPortInterface
{
    public function defaultTierNames(): array
    {
        return array_map(
            static fn ($tier): string => $tier->name,
            QualityLadder::defaultTiers(),
        );
    }

    public function defaultTiers(): array
    {
        return array_map(
            static fn ($tier): array => [
                'name' => $tier->name,
                'videoBitrate' => $tier->videoBitrate,
            ],
            QualityLadder::defaultTiers(),
        );
    }
}
