<?php

namespace App\Modules\Recommendation\Algorithms;

class MinMaxNorm
{
    public function __construct(
        private readonly array $values,
        private readonly float|int|null $min = null,
        private readonly float|int|null $max = null,
    )
    {
    }

    public function calculate()
    {
        $norm = [];
        $min = $this->min ?? min($this->values);
        $max = $this->max ?? max($this->values);

        // Handle edge case where max equals min (prevent division by zero)
        if ($max === $min) {
            return array_fill(0, count($this->values), 0.5); // Return middle value
        }

        foreach ($this->values as $key => $value) {
            $numerator = $value - $min;
            $denominator = $max - $min;
            $minMaxNorm = $numerator / $denominator;
            $norm[$key] = $minMaxNorm; // Preserve keys
        }

        return $norm;
    }

    // Add a static method to normalize multiple feature arrays together
    public static function normalizeFeatures(array $featureSets): array
    {
        $normalizedSets = [];
        $features = [];

        // First, collect all feature values to determine global min/max
        foreach ($featureSets as $set) {
            foreach ($set as $feature => $value) {
                $features[$feature][] = $value;
            }
        }

        // Calculate min/max for each feature
        $featureMinMax = array_map(function ($values) {
            return [
                'min' => min($values),
                'max' => max($values)
            ];
        }, $features);

        // Normalize each feature set
        foreach ($featureSets as $setKey => $set) {
            $normalizedSets[$setKey] = [];
            foreach ($set as $feature => $value) {
                $min = $featureMinMax[$feature]['min'];
                $max = $featureMinMax[$feature]['max'];

                if ($max === $min) {
                    $normalizedSets[$setKey][$feature] = 0.5;
                } else {
                    $normalizedSets[$setKey][$feature] = ($value - $min) / ($max - $min);
                }
            }
        }

        return $normalizedSets;
    }
}