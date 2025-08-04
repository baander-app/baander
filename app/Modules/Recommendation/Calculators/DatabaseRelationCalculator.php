<?php

namespace App\Modules\Recommendation\Calculators;

use App\Modules\Recommendation\Contracts\CalculatorInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DatabaseRelationCalculator implements CalculatorInterface
{
    /**
     * Calculate recommendations based on database relationships
     *
     * @param mixed $sourceData Source model(s) to calculate recommendations for
     * @param array $configuration Configuration parameters for calculation
     * @return array Array of recommendations [sourceId => [targetId => score]]
     * @throws InvalidArgumentException
     */
    public function calculate($sourceData, array $configuration): array
    {
        // Validate required configuration parameters
        $this->validateConfiguration($configuration);

        // Extract configuration parameters
        $dataTable = $configuration['data_table'];
        $dataField = $configuration['data_field'];
        $groupField = $configuration['group_field'];
        $maxRecommendations = $configuration['count'] ?? config('recommendation.defaults.count', 10);

        // Query the database to get relationship data
        $query = DB::table($dataTable)
            ->select(
                DB::raw("{$groupField} as group_field"),
                DB::raw("{$dataField} as data_field")
            );

        // Apply data filters if specified
        if (isset($configuration['data_table_filter']) && is_array($configuration['data_table_filter'])) {
            foreach ($configuration['data_table_filter'] as $field => $filterConfig) {
                $this->applyFilter($query, $field, $filterConfig);
            }
        }

        $relationData = $query->get();

        // Group the data by the group field
        $groupedData = [];
        foreach ($relationData as $row) {
            if (!isset($groupedData[$row->group_field])) {
                $groupedData[$row->group_field] = [];
            }

            $groupedData[$row->group_field][] = $row->data_field;
        }

        // Calculate co-occurrence based recommendations
        $recommendations = $this->calculateCoOccurrenceRecommendations(
            $groupedData,
            $maxRecommendations
        );

        return $recommendations;
    }

    /**
     * Validate that required configuration parameters are present
     *
     * @param array $configuration
     * @return void
     * @throws InvalidArgumentException
     */
    private function validateConfiguration(array $configuration): void
    {
        $requiredParams = ['data_table', 'data_field', 'group_field'];

        foreach ($requiredParams as $param) {
            if (!isset($configuration[$param])) {
                throw new InvalidArgumentException("Missing required configuration parameter: {$param}");
            }
        }
    }

    /**
     * Apply a filter to the database query
     *
     * @param Builder $query
     * @param string $field
     * @param mixed $filterConfig
     * @return void
     */
    private function applyFilter($query, string $field, $filterConfig): void
    {
        // Handle array format filter [operator, value]
        if (is_array($filterConfig) && count($filterConfig) === 2) {
            [$operator, $value] = $filterConfig;

            // Handle callable value (dynamic filters)
            if (is_callable($value)) {
                $value = $value();
            }

            // Apply appropriate filter based on operator
            switch (strtoupper($operator)) {
                case '=':
                    $query->where($field, $value);
                    break;
                case 'IN':
                    $query->whereIn($field, (array)$value);
                    break;
                case 'NOT IN':
                    $query->whereNotIn($field, (array)$value);
                    break;
                case 'BETWEEN':
                    if (is_array($value) && count($value) === 2) {
                        $query->whereBetween($field, $value);
                    }
                    break;
                default:
                    $query->where($field, $operator, $value);
            }
        }
        // Handle direct value comparison (equals)
        else {
            $query->where($field, $filterConfig);
        }
    }

    /**
     * Calculate recommendations based on co-occurrence in groups
     *
     * @param array $groupedData
     * @param int $maxRecommendations
     * @return array
     */
    private function calculateCoOccurrenceRecommendations(array $groupedData, int $maxRecommendations): array
    {
        $coOccurrenceScores = [];
        $recommendations = [];

        // Calculate co-occurrence scores
        foreach ($groupedData as $group) {
            foreach ($group as $item1) {
                foreach ($group as $item2) {
                    // Skip self-recommendations
                    if ($item1 === $item2) {
                        continue;
                    }

                    if (!isset($coOccurrenceScores[$item1])) {
                        $coOccurrenceScores[$item1] = [];
                    }

                    if (!isset($coOccurrenceScores[$item1][$item2])) {
                        $coOccurrenceScores[$item1][$item2] = 0;
                    }

                    // Increment co-occurrence score
                    $coOccurrenceScores[$item1][$item2]++;
                }
            }
        }

        // Sort and limit recommendations
        foreach ($coOccurrenceScores as $sourceId => $targets) {
            arsort($targets);
            $recommendations[$sourceId] = array_slice($targets, 0, $maxRecommendations, true);
        }

        return $recommendations;
    }
}