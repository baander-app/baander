<?php

namespace App\Modules\Recommendation\Algorithms;

use App\Modules\Recommendation\Contracts\AlgorithmInterface;

class EuclideanDistance implements AlgorithmInterface
{
    public function __construct(
        private readonly array $a,
        private readonly array $b,
    )
    {
    }

    public function calculate(bool $returnDistance = false)
    {
        // Ensure both vectors have the same dimensions
        $dimensions = array_unique(array_merge(array_keys($this->a), array_keys($this->b)));
        $sum = 0;

        foreach ($dimensions as $dimension) {
            $valA = $this->a[$dimension] ?? 0;
            $valB = $this->b[$dimension] ?? 0;
            $diff = $valA - $valB;
            $sum += $diff * $diff;
        }

        $distance = sqrt($sum);

        // For similarity, normalize to [0,1] range
        if (!$returnDistance) {
            // Compute maximum possible distance for normalization
            // For vectors in range [0,1], max distance is sqrt(dimensions)
            $maxDistance = sqrt(count($dimensions));
            return 1 - min($distance / $maxDistance, 1.0);
        }

        return $distance;
    }
}