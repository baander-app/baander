<?php

namespace App\Modules\Recommendation\Calculators;

use App\Modules\Recommendation\Algorithms\{EuclideanDistance, HammingDistance, JaccardIndex, MinMaxNorm};
use App\Modules\Recommendation\Contracts\CalculatorInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class SimilarityCalculator implements CalculatorInterface
{
    /**
     * Calculate recommendations based on similarity metrics
     *
     * @param mixed $sourceData Source model(s) to calculate recommendations for
     * @param array $configuration Configuration parameters for calculation
     * @return array Array of recommendations [sourceId => [targetId => score]]
     */
    public function calculate($sourceData, array $configuration): array
    {
        // Ensure we have a collection of models
        $models = $this->normalizeSourceData($sourceData);

        if ($models->isEmpty()) {
            return [];
        }

        $maxRecommendations = $configuration['count'] ?? config('recommendation.defaults.count', 10);
        $recommendations = [];

        // Cache extracted features to avoid redundant processing
        $cachedTaxonomies = [];
        $cachedFeatures = [];
        $cachedNumericValues = [];

        // Extract all features once for better performance
        foreach ($models as $model) {
            $modelId = $model->getKey();

            if (isset($configuration['similarity_taxonomy_attributes'])) {
                $cachedTaxonomies[$modelId] = $this->generateTaxonomies(
                    $model,
                    $configuration['similarity_taxonomy_attributes']
                );
            }

            if (isset($configuration['similarity_feature_attributes'])) {
                $cachedFeatures[$modelId] = $this->extractFeatures(
                    $model,
                    $configuration['similarity_feature_attributes']
                );
            }

            if (isset($configuration['similarity_numeric_value_attributes'])) {
                $cachedNumericValues[$modelId] = $this->extractNumericValues(
                    $model,
                    $configuration['similarity_numeric_value_attributes']
                );
            }
        }

        // Normalize numeric values across all models
        $normalizedNumericValues = [];
        if (!empty($cachedNumericValues)) {
            $normalizedNumericValues = MinMaxNorm::normalizeFeatures($cachedNumericValues);
        }

        // Calculate similarity between each pair of models
        foreach ($models as $sourceModel) {
            $sourceId = $sourceModel->getKey();
            $similarities = [];

            foreach ($models as $targetModel) {
                $targetId = $targetModel->getKey();

                // Skip self-comparison
                if ($sourceId === $targetId) {
                    continue;
                }

                $similarityScore = $this->calculateSimilarityScore(
                    $sourceId,
                    $targetId,
                    $configuration,
                    $cachedTaxonomies,
                    $cachedFeatures,
                    $normalizedNumericValues
                );

                // Only include models with non-zero similarity
                if ($similarityScore > 0) {
                    $similarities[$targetId] = $similarityScore;
                }
            }

            // Sort by similarity score (descending) and limit to max recommendations
            arsort($similarities);
            $recommendations[$sourceId] = array_slice($similarities, 0, $maxRecommendations, true);
        }

        return $recommendations;
    }

    /**
     * Normalize source data to a collection of models
     *
     * @param mixed $sourceData
     * @return Collection
     */
    private function normalizeSourceData($sourceData)
    {
        if ($sourceData instanceof Model) {
            return collect([$sourceData]);
        }

        if ($sourceData instanceof Collection) {
            return $sourceData;
        }

        if (is_array($sourceData)) {
            return collect($sourceData);
        }

        // Query all models of the specified class
        if (is_string($sourceData) && class_exists($sourceData)) {
            return $sourceData::all();
        }

        return collect([]);
    }

    /**
     * Calculate similarity score between two models
     *
     * @param int|string $sourceId
     * @param int|string $targetId
     * @param array $configuration
     * @param array $cachedTaxonomies
     * @param array $cachedFeatures
     * @param array $normalizedNumericValues
     * @return float Similarity score between 0-100
     */
    private function calculateSimilarityScore(
        $sourceId,
        $targetId,
        array $configuration,
        array $cachedTaxonomies,
        array $cachedFeatures,
        array $normalizedNumericValues
    ): float {
        $weights = [
            'taxonomy' => $configuration['similarity_taxonomy_weight'] ?? 1.0,
            'feature'  => $configuration['similarity_feature_weight'] ?? 0.0,
            'numeric'  => $configuration['similarity_numeric_value_weight'] ?? 0.0,
        ];

        $scores = [
            'taxonomy' => 0,
            'feature'  => 0,
            'numeric'  => 0,
        ];

        // Calculate taxonomy similarity if configured
        if ($weights['taxonomy'] > 0 &&
            !empty($configuration['similarity_taxonomy_attributes']) &&
            isset($cachedTaxonomies[$sourceId]) &&
            isset($cachedTaxonomies[$targetId])) {

            $sourceTaxonomies = $cachedTaxonomies[$sourceId];
            $targetTaxonomies = $cachedTaxonomies[$targetId];

            if (!empty($sourceTaxonomies) && !empty($targetTaxonomies)) {
                try {
                    $jaccardIndex = JaccardIndex::fromArrays($sourceTaxonomies, $targetTaxonomies);
                    $scores['taxonomy'] = $jaccardIndex->calculate() * 100;
                } catch (Throwable $e) {
                    // Handle calculation errors gracefully
                    $scores['taxonomy'] = 0;
                }
            }
        }

        // Calculate feature similarity if configured
        if ($weights['feature'] > 0 &&
            !empty($configuration['similarity_feature_attributes']) &&
            isset($cachedFeatures[$sourceId]) &&
            isset($cachedFeatures[$targetId])) {

            $sourceFeatures = $cachedFeatures[$sourceId];
            $targetFeatures = $cachedFeatures[$targetId];

            if (!empty($sourceFeatures) && !empty($targetFeatures)) {
                try {
                    // Calculate feature similarity using Hamming distance for feature arrays
                    $hammingDistance = HammingDistance::forFeatureArrays($sourceFeatures, $targetFeatures);
                    $scores['feature'] = (1 - $hammingDistance) * 100;
                } catch (Throwable $e) {
                    $scores['feature'] = 0;
                }
            }
        }

        // Calculate numeric similarity if configured
        if ($weights['numeric'] > 0 &&
            !empty($configuration['similarity_numeric_value_attributes']) &&
            isset($normalizedNumericValues[$sourceId]) &&
            isset($normalizedNumericValues[$targetId])) {

            $normalizedSource = $normalizedNumericValues[$sourceId];
            $normalizedTarget = $normalizedNumericValues[$targetId];

            if (!empty($normalizedSource) && !empty($normalizedTarget)) {
                try {
                    $euclideanDistance = new EuclideanDistance($normalizedSource, $normalizedTarget);
                    // The improved EuclideanDistance class handles similarity calculation correctly
                    $scores['numeric'] = $euclideanDistance->calculate(false) * 100;
                } catch (Throwable $e) {
                    $scores['numeric'] = 0;
                }
            }
        }

        // Calculate weighted average of scores
        $totalWeight = array_sum($weights);

        if ($totalWeight === 0) {
            return 0;
        }

        $weightedScore = 0;

        foreach ($scores as $type => $score) {
            $weightedScore += ($score * $weights[$type]);
        }

        return $weightedScore / $totalWeight;
    }

    /**
     * Generate taxonomies (categorical data) from model
     *
     * @param Model $model
     * @param array $taxonomyFields
     * @return array
     */
    private function generateTaxonomies(Model $model, array $taxonomyFields): array
    {
        $taxonomies = [];

        foreach ($taxonomyFields as $relationField) {
            if (is_array($relationField)) {
                // Handle relation => attribute format
                foreach ($relationField as $relation => $attribute) {
                    if (method_exists($model, $relation)) {
                        $relatedModels = $model->$relation;

                        if ($relatedModels) {
                            if ($relatedModels instanceof Collection) {
                                foreach ($relatedModels as $relatedModel) {
                                    if (isset($relatedModel->$attribute)) {
                                        $taxonomies[] = $relation . ':' . $relatedModel->$attribute;
                                    }
                                }
                            } else if (isset($relatedModels->$attribute)) {
                                $taxonomies[] = $relation . ':' . $relatedModels->$attribute;
                            }
                        }
                    }
                }
            } else {
                // Handle direct attributes
                if (isset($model->$relationField)) {
                    $taxonomies[] = $relationField . ':' . $model->$relationField;
                }
            }
        }

        return $taxonomies;
    }

    /**
     * Extract boolean feature values from model
     *
     * @param Model $model
     * @param array $featureFields
     * @return array
     */
    private function extractFeatures(Model $model, array $featureFields): array
    {
        $features = [];

        foreach ($featureFields as $field) {
            $features[$field] = isset($model->$field) && $model->$field ? 1 : 0;
        }

        return $features;
    }

    /**
     * Extract numeric values from model
     *
     * @param Model $model
     * @param array $numericFields
     * @return array
     */
    private function extractNumericValues(Model $model, array $numericFields): array
    {
        $numericValues = [];

        foreach ($numericFields as $field) {
            $numericValues[$field] = isset($model->$field) ? (float)$model->$field : 0;
        }

        return $numericValues;
    }
}