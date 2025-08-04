<?php

namespace App\Modules\Recommendation\Services;

use App\Models\Recommendation;
use App\Modules\Recommendation\Contracts\CalculatorInterface;
use Illuminate\Cache\TaggableStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

class RecommendationService
{
    /**
     * Registered recommendation calculators
     *
     * @var array<string, CalculatorInterface>
     */
    private array $calculators = [];

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor with dependency injection
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Register a calculator for a specific algorithm
     *
     * @param string $algorithm
     * @param CalculatorInterface $calculator
     * @return $this
     */
    public function registerCalculator(string $algorithm, CalculatorInterface $calculator): self
    {
        $this->calculators[$algorithm] = $calculator;
        return $this;
    }

    /**
     * Get calculator for an algorithm
     *
     * @param string $algorithm
     * @return CalculatorInterface
     * @throws InvalidArgumentException
     */
    public function getCalculator(string $algorithm): CalculatorInterface
    {
        if (!isset($this->calculators[$algorithm])) {
            throw new InvalidArgumentException("No calculator registered for algorithm: {$algorithm}");
        }

        return $this->calculators[$algorithm];
    }

    /**
     * Get available algorithms
     *
     * @return array
     */
    public function getAvailableAlgorithms(): array
    {
        return array_keys($this->calculators);
    }

    /**
     * Get recommendations for a model with optional caching
     *
     * @param Model $model
     * @param string $name
     * @param bool $refresh Force refresh even when cache is enabled
     * @return Collection
     */
    public function getRecommendations(Model $model, string $name, bool $refresh = false): Collection
    {
        $cacheEnabled = config('recommendation.cache.enabled', true) && !$refresh;
        $cacheTtl = config('recommendation.cache.ttl', 3600);

        if (!$cacheEnabled) {
            return $this->fetchRecommendations($model, $name);
        }

        $modelClass = get_class($model);
        $cacheKey = "recommendations:{$model->getTable()}:{$model->getKey()}:{$name}";
        $tagName = "recommendations:{$modelClass}:{$name}";

        // Use cache tags if the cache store supports them
        if (Cache::getStore() instanceof TaggableStore) {
            return Cache::tags($tagName)->remember($cacheKey, $cacheTtl, function () use ($model, $name) {
                return $this->fetchRecommendations($model, $name);
            });
        }

        // Fall back to regular caching if tags not supported
        return Cache::remember($cacheKey, $cacheTtl, function () use ($model, $name) {
            return $this->fetchRecommendations($model, $name);
        });
    }

    /**
     * Fetch recommendations from the database
     *
     * @param Model $model
     * @param string $name
     * @return Collection
     */
    private function fetchRecommendations(Model $model, string $name): Collection
    {
        $configMethod = 'getRecommendationConfig';

        if (!method_exists($model, $configMethod)) {
            $this->logger->warning("Model " . get_class($model) . " doesn't implement getRecommendationConfig method");
            return collect();
        }

        $config = $model->$configMethod()[$name] ?? null;

        if ($config === null) {
            $this->logger->warning("No recommendation config found for " . get_class($model) . " with name '{$name}'");
            return collect();
        }

        $targetType = $config['data_field_type'] ?? get_class($model);

        // Get recommendations with their position values
        $recommendations = Recommendation::whereSourceType(get_class($model))
            ->whereName($name)
            ->whereTargetType($targetType)
            ->whereSourceId($model->getKey())
            ->orderBy('position') // Use the explicit position for ordering
            ->get();

        if ($recommendations->isEmpty()) {
            return collect();
        }

        // Extract target IDs while maintaining their order
        $orderedTargetIds = $recommendations->pluck('target_id')->toArray();

        // Load eager relations if specified
        $eagerLoad = $config['with'] ?? [];
        $query = $targetType::whereIn('id', $orderedTargetIds);

        if (!empty($eagerLoad)) {
            $query->with($eagerLoad);
        }

        // Get the result models
        $result = $query->get();

        // Apply custom ordering based on configuration
        $order = $config['order'] ?? config('recommendation.defaults.order', 'desc');

        if ($order === 'random') {
            return $result->shuffle();
        }

        // Create a position lookup table
        $positionMap = [];
        foreach ($recommendations as $recommendation) {
            $positionMap[$recommendation->target_id] = $recommendation->position;
        }

        // Order by the explicit position
        $orderedResult = $result->sortBy(function ($model) use ($positionMap) {
            return $positionMap[$model->getKey()] ?? PHP_INT_MAX;
        });

        // Reverse the order if we want ascending order of scores (which means descending order of positions)
        if ($order === 'asc') {
            return $orderedResult->reverse();
        }

        return $orderedResult;
    }

    /**
     * Generate recommendations for all models of a specific class
     *
     * @param string $modelClass
     * @param string $name
     * @param array $options Additional options for generation
     * @return int
     */
    public function generateRecommendations(string $modelClass, string $name, array $options = []): int
    {
        $configMethod = 'getRecommendationConfig';

        if (!method_exists($modelClass, $configMethod)) {
            $this->logger->warning("Model {$modelClass} doesn't implement {$configMethod}");
            return 0;
        }

        $config = $modelClass::$configMethod()[$name] ?? null;

        if ($config === null) {
            $this->logger->warning("No recommendation config found for {$modelClass} with name '{$name}'");
            return 0;
        }

        $algorithm = $config['algorithm'] ?? 'db_relation';

        try {
            $calculator = $this->getCalculator($algorithm);
        } catch (InvalidArgumentException $e) {
            $this->logger->error("Failed to get calculator: " . $e->getMessage());
            return 0;
        }

        // Get source data - either all models or a filtered subset
        $query = $modelClass::query();

        if (isset($options['filter']) && is_callable($options['filter'])) {
            $options['filter']($query);
        }

        $models = $query->get();

        $this->logger->info("Generating recommendations for {$name} using {$algorithm} algorithm on " . $models->count() . " models");

        // Calculate recommendations
        try {
            $recommendations = $calculator->calculate($models, $config);
            return $this->saveRecommendations($modelClass, $name, $recommendations, $config, $options);
        } catch (Throwable $e) {
            $this->logger->error("Error generating recommendations: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Save calculated recommendations to the database
     *
     * @param string $modelClass
     * @param string $name
     * @param array $recommendations
     * @param array $config
     * @return int
     */
    private function saveRecommendations(
        string $modelClass,
        string $name,
        array $recommendations,
        array $config,
        array $options
    ): int {
        $targetType = $config['data_field_type'] ?? $modelClass;
        $insertedCount = 0;
        $batchSize = $config['batch_size'] ?? 1000;
        $userId = $options['user_id'] ?? null;

        // Use a transaction for better performance and data integrity
        DB::transaction(function () use (
            $modelClass,
            $name,
            $recommendations,
            $targetType,
            &$insertedCount,
            $batchSize,
            $userId
        ) {
            // Delete existing recommendations
            Recommendation::where('source_type', $modelClass)
                ->where('name', $name)
                ->delete();

            // Prepare data for bulk insert
            $recommendationsToInsert = [];
            $now = now();

            foreach ($recommendations as $sourceId => $targets) {
                // Process each source's targets
                $position = 1; // Start position at 1

                // Sort targets by score descending (higher scores first)
                arsort($targets);

                foreach ($targets as $targetId => $score) {
                    $row = [
                        'source_type' => $modelClass,
                        'source_id' => $sourceId,
                        'target_type' => $targetType,
                        'target_id' => $targetId,
                        'score' => $score, // Store original score for filtering/reference
                        'position' => $position, // Add explicit position for ordering
                        'name' => $name,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $row['user_id'] = $userId;

                    $recommendationsToInsert[] = $row;

                    $insertedCount++;
                    $position++; // Increment position for next item

                    // Insert in batches to prevent memory issues
                    if (count($recommendationsToInsert) >= $batchSize) {
                        Recommendation::insert($recommendationsToInsert);
                        $recommendationsToInsert = [];
                    }
                }
            }

            // Insert any remaining recommendations
            if (!empty($recommendationsToInsert)) {
                Recommendation::insert($recommendationsToInsert);
            }
        });

        // Clear cache for this recommendation set
        $this->clearRecommendationCache($modelClass, $name);
        $this->logger->info("Saved {$insertedCount} recommendations for {$modelClass}:{$name}");

        return $insertedCount;
    }

    /**
     * Clear cache for a specific model instance
     *
     * @param Model $model
     * @param string $name
     * @return void
     */
    public function clearModelRecommendationCache(Model $model, string $name): void
    {
        $modelClass = get_class($model);
        $cacheKey = "recommendations:{$model->getTable()}:{$model->getKey()}:$name";
        $tagName = "recommendations:$modelClass:$name";

        if (Cache::getStore() instanceof TaggableStore) {
            Cache::tags($tagName)->forget($cacheKey);
            return;
        }

        Cache::forget($cacheKey);
    }

    /**
     * Clear cache for a specific recommendation set
     *
     * @param string $modelClass
     * @param string $name
     * @return void
     */
    public function clearRecommendationCache(string $modelClass, string $name): void
    {
        // For stores that support tags, use tag-based clearing
        if (Cache::getStore() instanceof TaggableStore) {
            $tagName = "recommendations:{$modelClass}:{$name}";
            Cache::tags($tagName)->flush();
            return;
        }

        $model = new $modelClass;
        $tablePrefix = $model->getTable();

        // For Redis specifically, we can use pattern matching
        if (method_exists(Cache::getStore(), 'getRedis')) {
            try {
                $redis = Cache::getStore()->getRedis();
                $pattern = "laravel:recommendations:{$tablePrefix}:*:{$name}";

                // Use SCAN instead of KEYS for production safety
                $cursor = '0';
                do {
                    [$cursor, $keys] = $redis->scan($cursor, 'MATCH', $pattern, 'COUNT', 100);

                    // Delete the keys if any found
                    if (!empty($keys)) {
                        $redis->del($keys);
                    }
                } while ($cursor != '0');

                return;
            } catch (\Exception $e) {
                $this->logger->warning("Redis cache clearing failed: " . $e->getMessage());
            }
        }

        $this->logger->warning(
            "Cannot efficiently clear recommendation cache for {$modelClass}:{$name}. " .
            "Consider using a cache store that supports tags."
        );
    }

    /**
     * Generate recommendations for a specific model instance
     *
     * @param Model $model
     * @param string $name
     * @return int
     */
    public function generateRecommendationsForModel(Model $model, string $name): int
    {
        $configMethod = 'getRecommendationConfig';

        if (!method_exists($model, $configMethod)) {
            return 0;
        }

        $config = $model->$configMethod()[$name] ?? null;

        if ($config === null) {
            return 0;
        }

        $algorithm = $config['algorithm'] ?? 'db_relation';

        try {
            $calculator = $this->getCalculator($algorithm);
        } catch (InvalidArgumentException $e) {
            $this->logger->error("Failed to get calculator: " . $e->getMessage());
            return 0;
        }

        // Calculate recommendations for this specific model
        try {
            $recommendations = $calculator->calculate($model, $config);
        } catch (Throwable $e) {
            $this->logger->error("Error generating recommendations for model: " . $e->getMessage());
            return 0;
        }

        // Save recommendations only for this model
        $modelClass = get_class($model);
        $targetType = $config['data_field_type'] ?? $modelClass;
        $insertedCount = 0;

        DB::transaction(function () use ($model, $modelClass, $name, $recommendations, $targetType, &$insertedCount) {
            // Delete existing recommendations for this model
            Recommendation::where('source_type', $modelClass)
                ->where('name', $name)
                ->where('source_id', $model->getKey())
                ->delete();

            // Insert new recommendations
            $now = now();

            if (isset($recommendations[$model->getKey()])) {
                $position = 1;
                $targets = $recommendations[$model->getKey()];

                // Sort by score descending
                arsort($targets);

                foreach ($targets as $targetId => $score) {
                    Recommendation::create([
                        'source_type' => $modelClass,
                        'source_id' => $model->getKey(),
                        'target_type' => $targetType,
                        'target_id' => $targetId,
                        'score' => $score,
                        'position' => $position,
                        'name' => $name,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $insertedCount++;
                    $position++;
                }
            }
        });

        // Clear cache for this model
        $this->clearModelRecommendationCache($model, $name);

        return $insertedCount;
    }
}