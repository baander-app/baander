<?php

namespace App\Modules\Recommendation\Calculators;

use App\Modules\Recommendation\Algorithms\{CosineSimilarity, TfIdf};
use App\Modules\Recommendation\Contracts\CalculatorInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class ContentBasedCalculator implements CalculatorInterface
{
    /**
     * Calculate recommendations based on content similarity
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
        $contentField = $configuration['content_field'] ?? 'description';
        $recommendations = [];

        // Extract content from all models
        $documents = [];
        $modelIds = [];

        foreach ($models as $model) {
            if (isset($model->$contentField)) {
                $content = $this->processContent($model->$contentField);
                if (!empty($content)) {
                    $modelIds[] = $model->getKey();
                    $documents[] = $content;
                }
            }
        }

        // If we have fewer than 2 documents, we can't calculate recommendations
        if (count($documents) < 2) {
            return [];
        }

        // Calculate TF-IDF vectors
        $tfidf = new TfIdf($documents);
        $tfidfVectors = $tfidf->calculate();

        // Calculate similarity between each pair of models
        foreach ($modelIds as $i => $sourceId) {
            $similarities = [];

            foreach ($modelIds as $j => $targetId) {
                // Skip self-comparison
                if ($sourceId === $targetId) {
                    continue;
                }

                $sourceVector = $tfidfVectors[$i];
                $targetVector = $tfidfVectors[$j];

                // Calculate cosine similarity
                $cosineSimilarity = new CosineSimilarity($sourceVector, $targetVector);
                $similarityScore = $cosineSimilarity->calculate() * 100;

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
     * Process content text into a bag of words
     *
     * @param string $content
     * @return array
     */
    private function processContent(string $content): array
    {
        // Convert to lowercase
        $content = strtolower($content);

        // Remove special characters and numbers
        $content = preg_replace('/[^\p{L}\s]/u', ' ', $content);

        // Split into words
        $words = preg_split('/\s+/', $content, -1, PREG_SPLIT_NO_EMPTY);

        // Remove common stopwords
        $stopwords = ['the', 'and', 'a', 'to', 'of', 'in', 'is', 'that', 'it', 'with', 'for', 'as', 'on', 'at'];
        $words = array_diff($words, $stopwords);

        return $words;
    }

    /**
     * Normalize source data to a collection of models
     *
     * @param mixed $sourceData
     * @return Collection|\Illuminate\Support\Collection
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

        return collect();
    }
}