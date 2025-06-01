<?php

namespace App\Modules\Recommendation\Algorithms;

use App\Modules\Recommendation\Contracts\AlgorithmInterface;

class CosineSimilarity implements AlgorithmInterface
{
    public function __construct(
        private readonly array $a,
        private readonly array $b,
    )
    {
    }

    public function calculate()
    {
        // Get all dimensions from both vectors
        $dimensions = array_unique(array_merge(array_keys($this->a), array_keys($this->b)));

        // Calculate dot product
        $dotProduct = 0;
        $normA = 0;
        $normB = 0;

        foreach ($dimensions as $dimension) {
            $valueA = $this->a[$dimension] ?? 0;
            $valueB = $this->b[$dimension] ?? 0;

            $dotProduct += $valueA * $valueB;
            $normA += $valueA * $valueA;
            $normB += $valueB * $valueB;
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        // Avoid division by zero
        if ($normA == 0 || $normB == 0) {
            return 0;
        }

        // Cosine similarity formula: dot product / (|A| * |B|)
        return $dotProduct / ($normA * $normB);
    }
}
