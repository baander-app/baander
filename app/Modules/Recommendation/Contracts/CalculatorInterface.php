<?php

namespace App\Modules\Recommendation\Contracts;

interface CalculatorInterface
{
    /**
     * Calculate recommendations for the given source model(s)
     *
     * @param mixed $sourceData Source model(s) to calculate recommendations for
     * @param array $configuration Configuration parameters for the calculation
     * @return array Array of recommendations [sourceId => [targetId => score]]
     */
    public function calculate($sourceData, array $configuration): array;
}