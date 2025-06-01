
<?php

namespace App\Modules\Recommendation\Algorithms;

use App\Modules\Recommendation\Contracts\AlgorithmInterface;

class JaccardIndex implements AlgorithmInterface
{
    public function __construct(
        private readonly string $a,
        private readonly string $b,
        private readonly string $separator = ',',
    )
    {
    }

    public function calculate()
    {
        $a = explode($this->separator, $this->a);
        $b = explode($this->separator, $this->b);
        $intersection = array_unique(array_intersect($a, $b));
        $union = array_unique(array_merge($a, $b));

        // Handle edge case to prevent division by zero
        if (empty($union)) {
            return 0.0;
        }

        return count($intersection) / count($union);
    }

    // Add an alternative constructor for array inputs
    public static function fromArrays(array $a, array $b): self
    {
        return new self(
            implode(',', $a),
            implode(',', $b)
        );
    }
}