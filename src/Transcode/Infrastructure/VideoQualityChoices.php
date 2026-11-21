<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure;

use App\Media\Application\Port\VideoQualityChoicesPortInterface;
use App\QoL\Application\Port\AllowedQualityTiersPortInterface;
use App\Transcode\Domain\Service\QualityLadder;

final readonly class VideoQualityChoices implements VideoQualityChoicesPortInterface
{
    public function __construct(
        private AllowedQualityTiersPortInterface $qualityPolicy,
    ) {
    }

    public function choices(?int $sourceWidth, ?int $sourceHeight): array
    {
        if ($sourceWidth === null || $sourceHeight === null || $sourceWidth <= 0 || $sourceHeight <= 0) {
            return [];
        }

        $qualified = $this->qualityPolicy->qualifiedTierNames();
        $choices = [];
        foreach (QualityLadder::defaultTiers() as $tier) {
            if (
                $tier->width > $sourceWidth
                || $tier->height > $sourceHeight
                || !in_array($tier->name, $qualified, true)
            ) {
                continue;
            }
            $choices[] = [
                'id' => $tier->name,
                'label' => $tier->name === '4K' ? '2160p / 4K' : $tier->name,
                'width' => $tier->width,
                'height' => $tier->height,
            ];
        }

        return $choices;
    }
}
