<?php

namespace App\Modules\Recommendation\Algorithms;

use App\Modules\Recommendation\Contracts\AlgorithmInterface;

class HammingDistance implements AlgorithmInterface
{
    public function __construct(
        private readonly string $a,
        private readonly string $b,
        private readonly bool $returnDistance = false,
    )
    {
    }

    public function calculate()
    {
        // Make both strings the same length with proper padding
        $maxLength = max(strlen($this->a), strlen($this->b));
        $firstStr = str_pad($this->a, $maxLength, ' ');
        $secondStr = str_pad($this->b, $maxLength, ' ');

        // Count differing characters
        $distance = 0;
        for ($i = 0; $i < $maxLength; $i++) {
            if ($firstStr[$i] !== $secondStr[$i]) {
                $distance++;
            }
        }

        if ($this->returnDistance) {
            return $distance;
        }

        // Return similarity score (normalized to [0,1])
        return ($maxLength > 0) ? ($maxLength - $distance) / $maxLength : 1.0;
    }

    // Add method for comparing binary feature arrays
    public static function forFeatureArrays(array $a, array $b): float
    {
        $features = array_unique(array_merge(array_keys($a), array_keys($b)));
        $distance = 0;

        foreach ($features as $feature) {
            if (($a[$feature] ?? 0) != ($b[$feature] ?? 0)) {
                $distance++;
            }
        }

        return ($features) ? ($distance / count($features)) : 0;
    }
}
