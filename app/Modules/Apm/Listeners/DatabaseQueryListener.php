<?php

namespace App\Modules\Apm\Listeners;

use App\Modules\Apm\OctaneApmManager;
use Elastic\Apm\SpanInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Psr\Log\LoggerInterface;
use Tpetry\PostgresqlEnhanced\PostgresEnhancedConnection;

class DatabaseQueryListener
{
    /**
     * The only supported database subtype
     */
    private const string DB_SUBTYPE_POSTGRESQL = 'postgresql';

    private array $activeQueries = [];

    /**
     * Tracks active Eloquent parent spans by model/builder class
     */
    private array $activeEloquentSpans = [];

    public function __construct(
        private ?LoggerInterface $logger = null,
    )
    {
    }

    public function register(): void
    {
        // Use Laravel's built-in beforeExecuting callback to capture queries before execution
        DB::beforeExecuting($this->beforeQueryExecution(...));

        // Use QueryExecuted event to capture queries after completion
        DB::listen(function ($event) {
            $this->handleQueryExecuted($event);
        });

        // Optional: Monitor specific Laravel events if configured
        // Note: This can create many spans and impact performance, so it's disabled by default
        if (config('apm.monitoring.events', false)) {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            // Listen only to specific events instead of all events to prevent excessive span creation
            $monitoredEvents = config('apm.monitoring.event_patterns', []);
            if (!empty($monitoredEvents)) {
                foreach ($monitoredEvents as $eventPattern) {
                    Event::listen($eventPattern, function ($event) use ($manager, $eventPattern) {
                        $eventName = is_object($event) ? get_class($event) : $eventPattern;
                        $manager->createSpan($eventName, 'event', 'laravel', 'fire');
                    });
                }
            }
        }

        // Enhanced Eloquent query tracking
        if (config('apm.monitoring.eloquent_advanced', true)) {
            $this->registerAdvancedEloquentTracking();
        }

    }

    private function registerAdvancedEloquentTracking(): void
    {
        // Track collection operations that might indicate N+1 issues
        Event::listen('eloquent.retrieved:*', function ($eventName, $models) {
            if (count($models) > 100) {
                /** @var OctaneApmManager $manager */
                $manager = App::make(OctaneApmManager::class);

                $span = $manager->createSpan(
                    'Large Collection Retrieved',
                    'app',
                    'eloquent',
                    'collection'
                );

                if ($span) {
                    $manager->addSpanTag($span, 'eloquent.collection_size', (string)count($models));
                    $manager->addSpanTag($span, 'eloquent.warning', 'large_collection');
                    $span->setOutcome('success');
                    $span->end();
                }
            }
        });
    }

    public function registerEloquentEventListeners(): void
    {
        /** @var OctaneApmManager $manager */
        $manager = App::make(OctaneApmManager::class);

        $events = [
            'eloquent.creating', 'eloquent.created',
            'eloquent.updating', 'eloquent.updated',
            'eloquent.saving', 'eloquent.saved',
            'eloquent.deleting', 'eloquent.deleted',
            'eloquent.restoring', 'eloquent.restored',
            'eloquent.retrieved', 'eloquent.replicating'
        ];

        foreach ($events as $event) {
            Event::listen($event, function ($eventName, $models) use ($manager) {
                if (!empty($models)) {
                    $model = $models[0];
                    $modelClass = get_class($model);
                    $modelName = substr($modelClass, strrpos($modelClass, '\\') + 1);

                    $span = $manager->createSpan(
                        "Eloquent Event: {$modelName} {$eventName}",
                        'app',
                        'eloquent',
                        'event'
                    );

                    if ($span) {
                        $manager->addSpanTag($span, 'eloquent.event', $eventName);
                        $manager->addSpanTag($span, 'eloquent.model', $modelName);

                        if (method_exists($model, 'getKey') && $model->getKey()) {
                            $manager->addSpanTag($span, 'eloquent.model_id', (string)$model->getKey());
                        }

                        $span->setOutcome('success');
                        $span->end();
                    }
                }
            });
        }
    }

    public function handleQueryExecuted(QueryExecuted $event): void
    {
        if (!config('apm.monitoring.database', true)) {
            return;
        }

        $queryId = $this->generateQueryId($event->sql, $event->bindings);

        // Check if we have pre-execution data
        if (isset($this->activeQueries[$queryId])) {
            $this->completeQueryTracking($queryId, $event);
        } else {
            // Fallback for queries we missed in beforeExecuting
            $this->handleMissedQuery($event);
        }
    }

    private function generateQueryId(string $sql, array $bindings): string
    {
        return hash('sha256', $sql . serialize($bindings) . getmypid());
    }

    private function completeQueryTracking(string $queryId, QueryExecuted $event): void
    {
        $queryInfo = $this->activeQueries[$queryId];
        $span = $queryInfo['span'];
        $manager = $queryInfo['manager'];
        $needsTransactionEnd = $queryInfo['needs_transaction_end'];
        $eloquentGroupKey = $queryInfo['eloquent_group_key'] ?? null;

        try {
            // Use Laravel's provided execution time
            $isEloquent = $queryInfo['is_eloquent'] ?? false;
            $this->addPostExecutionTags($manager, $span, $event, $isEloquent);

            // Set span outcome
            $span->setOutcome('success');
            $span->end();

            // Check if we need to complete the parent Eloquent span
            if ($isEloquent && $eloquentGroupKey) {
                // Add this query's duration to the parent span's total
                if (isset($this->activeEloquentSpans[$eloquentGroupKey])) {
                    if (!isset($this->activeEloquentSpans[$eloquentGroupKey]['total_duration_ms'])) {
                        $this->activeEloquentSpans[$eloquentGroupKey]['total_duration_ms'] = 0;
                    }
                    $this->activeEloquentSpans[$eloquentGroupKey]['total_duration_ms'] += $event->time;
                }

                $this->checkAndCompleteEloquentSpan($eloquentGroupKey);
            }

            // Handle transaction cleanup
            if ($needsTransactionEnd) {
                $manager->setTransactionResult('success');
                $manager->setTransactionOutcome('success');
                $manager->endTransaction();
            }

        } catch (\Throwable $e) {
            $this->logger?->error('Failed to complete query tracking', [
                'exception' => $e->getMessage(),
                'sql'       => $event->sql,
            ]);
        } finally {
            unset($this->activeQueries[$queryId]);
        }
    }

    private function addPostExecutionTags(OctaneApmManager $manager, SpanInterface $span, QueryExecuted $event, bool $isEloquent = false): void
    {
        $durationMs = $event->time;
        $span->context()->setLabel('db.duration.ms', (string)round($durationMs, 3));

        if ($isEloquent) {
            $manager->addSpanTag($span, 'db.framework', 'eloquent');
            $this->addEloquentRelationTags($manager, $span);

            // Add performance analysis
            $this->addPerformanceAnalysis($manager, $span, $event);

            // Detect potential N+1 queries
            $this->detectPotentialN1Queries($manager, $span, $event);
        }

        // Categorize query performance
        if ($durationMs > 1000) {
            $manager->addSpanTag($span, 'db.slow_query', 'true');
            $manager->addSpanTag($span, 'db.performance', 'very_slow');
        } elseif ($durationMs > 500) {
            $manager->addSpanTag($span, 'db.performance', 'slow');
        } elseif ($durationMs > 100) {
            $manager->addSpanTag($span, 'db.performance', 'moderate');
        } else {
            $manager->addSpanTag($span, 'db.performance', 'fast');
        }

        // Add query complexity analysis
        $this->analyzeQueryComplexity($manager, $span, $event->sql);
    }

    private function addPerformanceAnalysis(OctaneApmManager $manager, SpanInterface $span, QueryExecuted $event): void
    {
        $sql = strtolower($event->sql);

        // Detect potentially expensive operations
        if (str_contains($sql, 'order by') && !str_contains($sql, 'limit')) {
            $manager->addSpanTag($span, 'db.warning', 'order_without_limit');
        }

        if (str_contains($sql, 'like %')) {
            $manager->addSpanTag($span, 'db.warning', 'leading_wildcard_like');
        }

        if (preg_match('/select \* from/', $sql)) {
            $manager->addSpanTag($span, 'db.warning', 'select_star');
        }

        // Count joins
        $joinCount = substr_count($sql, 'join');
        if ($joinCount > 0) {
            $manager->addSpanTag($span, 'db.join_count', (string)$joinCount);
            if ($joinCount > 3) {
                $manager->addSpanTag($span, 'db.warning', 'many_joins');
            }
        }
    }

    private function detectPotentialN1Queries(OctaneApmManager $manager, SpanInterface $span, QueryExecuted $event): void
    {
        static $queryPatterns = [];

        $pattern = $this->normalizeQueryPattern($event->sql);
        $currentTime = microtime(true);

        // Clean old patterns (older than 5 seconds)
        $queryPatterns = array_filter($queryPatterns, function($time) use ($currentTime) {
            return ($currentTime - $time) < 5.0;
        });

        // Check if we've seen this pattern recently
        if (isset($queryPatterns[$pattern])) {
            $count = count(array_keys($queryPatterns, $pattern));
            if ($count > 5) { // Threshold for N+1 detection
                $manager->addSpanTag($span, 'db.warning', 'potential_n_plus_1');
                $manager->addSpanTag($span, 'db.n_plus_1_count', (string)$count);
            }
        }

        $queryPatterns[$pattern] = $currentTime;
    }

    private function normalizeQueryPattern(string $sql): string
    {
        // Remove specific values and normalize the query pattern
        $pattern = preg_replace('/\?/', 'X', $sql);
        $pattern = preg_replace('/\d+/', 'N', $pattern);
        $pattern = preg_replace('/\'[^\']*\'/', 'S', $pattern);
        return strtolower(trim($pattern));
    }

    private function analyzeQueryComplexity(OctaneApmManager $manager, SpanInterface $span, string $sql): void
    {
        $sql = strtolower($sql);
        $complexity = 'simple';

        // Count various complexity indicators
        $subqueryCount = substr_count($sql, 'select') - 1; // Subtract main query
        $joinCount = substr_count($sql, 'join');
        $whereConditions = substr_count($sql, 'where') + substr_count($sql, 'and') + substr_count($sql, 'or');

        if ($subqueryCount > 0 || $joinCount > 2 || $whereConditions > 5) {
            $complexity = 'complex';
        } elseif ($joinCount > 0 || $whereConditions > 2) {
            $complexity = 'moderate';
        }

        $manager->addSpanTag($span, 'db.complexity', $complexity);

        if ($subqueryCount > 0) {
            $manager->addSpanTag($span, 'db.subquery_count', (string)$subqueryCount);
        }
    }


    /**
     * Add Eloquent relation-specific tags if available
     */
    private function addEloquentRelationTags(OctaneApmManager $manager, SpanInterface $span): void
    {
        $eloquentInfo = $this->getEloquentInfo();
        if ($eloquentInfo && !empty($eloquentInfo['is_relation'])) {
            $manager->addSpanTag($span, 'eloquent.is_relation', 'true');

            if (!empty($eloquentInfo['relation_name'])) {
                $manager->addSpanTag($span, 'eloquent.relation_name', $eloquentInfo['relation_name']);
            }

            if (!empty($eloquentInfo['related_model'])) {
                $relatedModelName = substr($eloquentInfo['related_model'], strrpos($eloquentInfo['related_model'], '\\') + 1);
                $manager->addSpanTag($span, 'eloquent.related_model', $relatedModelName);
            }
        }
    }

    private function getEloquentInfo(): ?array
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
        $eloquentClass = null;
        $eloquentMethod = null;
        $isEloquent = false;
        $relationName = null;
        $relatedModel = null;
        $isRelation = false;

        // Relation methods that we want to track
        $relationMethods = [
            'belongsTo',
            'hasMany',
            'hasOne',
            'belongsToMany',
            'morphTo',
            'morphMany',
            'morphOne',
            'morphToMany',
            'hasManyThrough',
            'hasOneThrough',
        ];

        foreach ($backtrace as $index => $trace) {
            $class = $trace['class'] ?? '';
            $function = $trace['function'] ?? '';

            // Check for Eloquent classes in the backtrace
            if (str_contains($class, 'Illuminate\\Database\\Eloquent') ||
                str_contains($class, 'App\\Modules\\Eloquent') ||
                str_contains($class, 'App\\Models')) {
                $isEloquent = true;

                // If we haven't found a specific model/builder yet, use this one
                if ($eloquentClass === null) {
                    $eloquentClass = $class;
                    $eloquentMethod = $function;
                }

                // If this is a model class, prefer it over builder classes
                if (str_contains($class, 'App\\Models\\') ||
                    (str_contains($class, 'Illuminate\\Database\\Eloquent\\') && !str_contains($class, 'Builder'))) {
                    $eloquentClass = $class;
                    $eloquentMethod = $function;

                    // Check if this is a relation method
                    if (in_array($function, $relationMethods)) {
                        $isRelation = true;
                        $relationName = $function;

                        // Try to get the relation name from the next frame if available
                        if (isset($backtrace[$index + 1])) {
                            $nextFrame = $backtrace[$index + 1];
                            if (isset($nextFrame['function']) && $nextFrame['function'] === 'getRelationValue') {
                                // The relation name is often in the args of getRelationValue
                                if (isset($nextFrame['args'][0]) && is_string($nextFrame['args'][0])) {
                                    $relationName = $nextFrame['args'][0];
                                }
                            }
                        }

                        // Try to determine the related model
                        if (method_exists($class, $function)) {
                            try {
                                $reflectionMethod = new \ReflectionMethod($class, $function);
                                $docComment = $reflectionMethod->getDocComment();
                                if ($docComment) {
                                    // Extract @return Model type from docblock
                                    if (preg_match('/@return\s+([\\\\a-zA-Z0-9_]+)/', $docComment, $matches)) {
                                        $relatedModel = $matches[1];
                                    }
                                }
                            } catch (\ReflectionException) {
                                // Ignore reflection errors
                            }
                        }
                    }

                    // Found a model, no need to look further unless we're still looking for relation info
                    if (!$isRelation) {
                        break;
                    }
                }
            }

            // Check for Eloquent-specific methods
            if (in_array($function, [
                'newEloquentBuilder',
                'newModelQuery',
                'newQuery',
                'getRelationValue',
            ])) {
                $isEloquent = true;

                // If we haven't found a specific class yet, use this one
                if ($eloquentClass === null) {
                    $eloquentClass = $class;
                    $eloquentMethod = $function;
                }

                // Check for relation loading
                if ($function === 'getRelationValue' && isset($trace['args'][0]) && is_string($trace['args'][0])) {
                    $isRelation = true;
                    $relationName = $trace['args'][0];
                }
            }

            // Check specifically for relation methods
            if (in_array($function, $relationMethods)) {
                $isEloquent = true;
                $isRelation = true;
                $relationName = $function;

                // If we haven't found a specific class yet, use this one
                if ($eloquentClass === null) {
                    $eloquentClass = $class;
                    $eloquentMethod = $function;
                }
            }
        }

        // MOVED THIS CHECK TO THE END - This was the problem!
        if (!$isEloquent) {
            return null;
        }

        // FIX: Generate a more stable group key that doesn't over-segment
        // Focus on the actual model and high-level operation rather than implementation details
        $groupKey = 'eloquent';

        // Use the actual model class if available
        if ($eloquentClass && (str_contains($eloquentClass, 'App\\Models\\') || str_contains($eloquentClass, 'App\\Modules\\'))) {
            $modelName = substr($eloquentClass, strrpos($eloquentClass, '\\') + 1);
            $groupKey = "eloquent:{$modelName}";
        }

        // For relations, use a consistent pattern
        if ($isRelation && $relationName) {
            $groupKey .= ":{$relationName}";
        }

        // Don't include method names that are implementation details
        // Only include them for specific high-level operations
        if ($eloquentMethod && in_array($eloquentMethod, ['create', 'update', 'delete', 'save'])) {
            $groupKey .= ":{$eloquentMethod}";
        }

        return [
            'is_eloquent'   => true,
            'class'         => $eloquentClass,
            'method'        => $eloquentMethod,
            'is_relation'   => $isRelation,
            'relation_name' => $relationName,
            'related_model' => $relatedModel,
            'group_key'     => $groupKey,
        ];
    }

    /**
     * Check if all child queries are done and complete the parent Eloquent span
     *
     * This method is called after each child query completes. It increments the
     * completed queries counter for the parent span and checks if all child queries
     * are done. If they are, it completes the parent span with summary information.
     *
     * For relation queries, it adds additional descriptive information about the
     * relation that was loaded.
     *
     * @param string $groupKey The unique key identifying the parent span group
     */
    private function checkAndCompleteEloquentSpan(string $groupKey): void
    {
        if (!isset($this->activeEloquentSpans[$groupKey])) {
            return;
        }

        $spanInfo = $this->activeEloquentSpans[$groupKey];
        $span = $spanInfo['span'];

        // Increment completed queries count
        $this->activeEloquentSpans[$groupKey]['completed_queries']++;

        // Accumulate query execution time instead of measuring elapsed time
        if (!isset($this->activeEloquentSpans[$groupKey]['total_duration_ms'])) {
            $this->activeEloquentSpans[$groupKey]['total_duration_ms'] = 0;
        }

        // Check if all queries in the group are completed
        if ($this->activeEloquentSpans[$groupKey]['completed_queries'] >= $this->activeEloquentSpans[$groupKey]['total_queries']) {
            // All child queries are done, complete the parent span
            $totalDuration = $this->activeEloquentSpans[$groupKey]['total_duration_ms'];

            // Add summary information using accumulated duration
            $span->context()->setLabel('eloquent.total_queries', (string)$spanInfo['total_queries']);
            $span->context()->setLabel('eloquent.duration_ms', (string)round($totalDuration, 3));

            // Add relation-specific summary information if this is a relation
            if (!empty($spanInfo['is_relation'])) {
                $span->context()->setLabel('eloquent.relation_summary', 'true');

                if (!empty($spanInfo['relation_name'])) {
                    $relationName = $spanInfo['relation_name'];
                    $modelName = substr($spanInfo['model'], strrpos($spanInfo['model'], '\\') + 1);

                    $summary = "Loaded relation '$relationName' for model '{$modelName}'";

                    if (!empty($spanInfo['related_model'])) {
                        $relatedModelName = substr($spanInfo['related_model'], strrpos($spanInfo['related_model'], '\\') + 1);
                        $summary .= " (related model: {$relatedModelName})";
                    }

                    $span->context()->setLabel('eloquent.relation_description', $summary);
                }
            }

            // Complete the span
            $span->setOutcome('success');
            $span->end();

            // Remove from active spans
            unset($this->activeEloquentSpans[$groupKey]);
        }
    }

    /**
     * Handle queries that we missed in beforeExecuting (fallback)
     *
     * This method is a fallback for queries that weren't captured by beforeQueryExecution.
     * This can happen when:
     *
     * 1. Queries are executed by packages that bypass Laravel's query builder
     * 2. Queries are executed before our listener is registered
     * 3. Queries are executed in a way that doesn't trigger the beforeExecuting event
     *
     * For these missed queries, we create a span after the fact using the information
     * from the QueryExecuted event. This ensures we don't miss any queries in our APM tracking.
     *
     * @param QueryExecuted $event The query executed event
     */
    private function handleMissedQuery(QueryExecuted $event): void
    {
        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            $dbSubtype = self::DB_SUBTYPE_POSTGRESQL;
            $eloquentInfo = $this->getEloquentInfo();

            // Create the span using the common method
            $spanInfo = $this->createQuerySpan($manager, $event->sql, $dbSubtype, $eloquentInfo);
            $span = $spanInfo['span'];
            $groupKey = $spanInfo['group_key'];
            $isEloquent = $spanInfo['is_eloquent'];

            if ($span) {
                $this->setDatabaseContextFromEvent($span, $event, $dbSubtype);
                $this->addEventSpanTags($manager, $span, $event, $dbSubtype, $isEloquent);

                $span->setOutcome('success');
                $span->end();

                // Check if we need to complete the parent Eloquent span
                if ($isEloquent && $groupKey) {
                    // Add this query's duration to the parent span's total
                    if (isset($this->activeEloquentSpans[$groupKey])) {
                        if (!isset($this->activeEloquentSpans[$groupKey]['total_duration_ms'])) {
                            $this->activeEloquentSpans[$groupKey]['total_duration_ms'] = 0;
                        }
                        $this->activeEloquentSpans[$groupKey]['total_duration_ms'] += $event->time;
                    }

                    $this->checkAndCompleteEloquentSpan($groupKey);
                }
            }

        } catch (\Throwable $e) {
            $this->logger?->error('Failed to handle missed query', [
                'exception' => $e->getMessage(),
                'sql'       => $event->sql,
            ]);
        }
    }

    private function createQuerySpan(
        OctaneApmManager $manager,
        string           $sql,
        string           $dbSubtype,
        ?array           $eloquentInfo = null,
    ): array
    {
        $isEloquent = $eloquentInfo !== null;
        $action = $isEloquent ? 'eloquent' : 'query';
        $parentSpan = null;
        $groupKey = null;

        // For Eloquent queries, get or create a parent span
        if ($isEloquent) {
            $parentSpan = $this->getOrCreateEloquentParentSpan($manager, $eloquentInfo, $dbSubtype);
            $groupKey = $eloquentInfo['group_key'];

            // Increment total queries count for this group
            if (isset($this->activeEloquentSpans[$groupKey])) {
                $this->activeEloquentSpans[$groupKey]['total_queries']++;
            }
        }

        // Create the span
        if ($isEloquent && $parentSpan) {
            // Create as child of the parent Eloquent span
            $span = $parentSpan->beginChildSpan(
                $this->formatQuery($sql),
                'db',
                $dbSubtype,
                $action,
            );
        } else {
            // Create as child of the transaction
            $span = $manager->createSpan(
                $this->formatQuery($sql),
                'db',
                $dbSubtype,
                $action,
            );
        }

        return [
            'span'        => $span,
            'parent_span' => $parentSpan,
            'group_key'   => $groupKey,
            'is_eloquent' => $isEloquent,
        ];
    }

    private function getOrCreateEloquentParentSpan(OctaneApmManager $manager, array $eloquentInfo, string $dbSubtype): ?SpanInterface
    {
        $groupKey = $eloquentInfo['group_key'];

        // If we already have a parent span for this group, return it
        if (isset($this->activeEloquentSpans[$groupKey])) {
            return $this->activeEloquentSpans[$groupKey]['span'];
        }

        // Create a new parent span for this Eloquent query group
        $modelClass = $eloquentInfo['class'] ?? 'Unknown';
        $methodName = $eloquentInfo['method'] ?? 'query';
        $isRelation = $eloquentInfo['is_relation'] ?? false;
        $relationName = $eloquentInfo['relation_name'] ?? null;
        $relatedModel = $eloquentInfo['related_model'] ?? null;

        // Create a descriptive span name
        $spanName = $this->createEloquentSpanName($eloquentInfo);

        $span = $manager->createSpan(
            $spanName,
            'db',
            $dbSubtype,
            'eloquent.group',
        );

        if ($span) {
            $manager->addSpanTag($span, 'db.framework', 'eloquent');
            $manager->addSpanTag($span, 'eloquent.model', $modelClass);
            $manager->addSpanTag($span, 'eloquent.method', $methodName);

            // Add relation-specific tags if this is a relation
            if ($isRelation) {
                $manager->addSpanTag($span, 'eloquent.is_relation', 'true');
                if ($relationName) {
                    $manager->addSpanTag($span, 'eloquent.relation_name', $relationName);
                }
                if ($relatedModel) {
                    $manager->addSpanTag($span, 'eloquent.related_model', $relatedModel);
                }
            }

            // Store the parent span
            $this->activeEloquentSpans[$groupKey] = [
                'span'              => $span,
                'model'             => $modelClass,
                'method'            => $methodName,
                'is_relation'       => $isRelation,
                'relation_name'     => $relationName,
                'related_model'     => $relatedModel,
                'start_time'        => microtime(true),
                'total_queries'     => 0,
                'completed_queries' => 0,
            ];
        }

        return $span;
    }

    /**
     * Create a descriptive span name for an Eloquent query
     *
     * @param array $eloquentInfo Information about the Eloquent query
     * @return string A descriptive span name
     */
    private function createEloquentSpanName(array $eloquentInfo): string
    {
        $modelClass = $eloquentInfo['class'] ?? 'Unknown';
        $modelName = substr($modelClass, strrpos($modelClass, '\\') + 1);
        $methodName = $eloquentInfo['method'] ?? 'query';
        $isRelation = $eloquentInfo['is_relation'] ?? false;
        $relationName = $eloquentInfo['relation_name'] ?? null;
        $relatedModel = $eloquentInfo['related_model'] ?? null;

        // Start with base name
        $spanName = "Eloquent:{$modelName}";

        // Add relation info to span name if available
        if ($isRelation && $relationName) {
            $spanName .= "->{$relationName}";
            if ($relatedModel) {
                $relatedModelName = substr($relatedModel, strrpos($relatedModel, '\\') + 1);
                $spanName .= " ({$relatedModelName})";
            }
        } else if (str_contains($modelClass, 'Illuminate\\Database\\Eloquent\\Relations\\')) {
            // For relation classes like BelongsTo, use a more descriptive name
            // Extract the parent model name from the backtrace if possible
            $parentModelName = $this->extractParentModelFromRelation(debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 15));
            if ($parentModelName) {
                $spanName = "Eloquent:$parentModelName->$modelName";
            } else {
                $spanName = "Eloquent:<->$modelName";
            }
        } else if ($modelName === 'Model' && $methodName === 'performUpdate') {
            // For Model::performUpdate, extract the actual model name from the object in the backtrace
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 15);
            foreach ($backtrace as $trace) {
                $object = $trace['object'] ?? null;
                if ($object && is_object($object) && method_exists($object, 'getTable')) {
                    // This is likely the actual model instance
                    $actualModelName = get_class($object);
                    $actualModelName = substr($actualModelName, strrpos($actualModelName, '\\') + 1);
                    $spanName = "Eloquent:$actualModelName::$methodName";
                    break;
                }
            }
        } else {
            $spanName .= "::{$methodName}";
        }

        return $spanName;
    }

    /**
     * Extract the parent model name from a relation class
     *
     * This method examines the backtrace to find the parent model of a relation.
     * It first looks for relation objects that have getParent and getRelated methods,
     * then tries to extract the parent model name from there. If that fails, it looks
     * for model classes in the backtrace.
     *
     * @param array $backtrace The debug backtrace with objects
     * @return string|null The parent model name (without namespace) or null if not found
     */
    private function extractParentModelFromRelation(array $backtrace): ?string
    {
        foreach ($backtrace as $trace) {
            $object = $trace['object'] ?? null;

            // Check if this is a relation object
            if ($object && is_object($object) && method_exists($object, 'getParent') && method_exists($object, 'getRelated')) {
                try {
                    // Get the parent model
                    $parent = $object->getParent();
                    if ($parent) {
                        // Get the class name of the parent model
                        $parentClass = get_class($parent);
                        // Extract just the model name without namespace
                        return substr($parentClass, strrpos($parentClass, '\\') + 1);
                    }
                } catch (\Throwable) {
                    // Ignore errors when trying to get parent
                }
            }

            // Fallback: Check if this is a model class with a relation method
            if (isset($trace['class']) && str_contains($trace['class'], 'App\\Models\\')) {
                return substr($trace['class'], strrpos($trace['class'], '\\') + 1);
            }
        }

        return null;
    }

    /**
     * Format a SQL query into a concise, human-readable description
     *
     * This method extracts the query type (SELECT, INSERT, etc.) and the table name
     * from the SQL query and combines them into a short description like "SELECT users".
     *
     * @param string $sql The SQL query to format
     * @return string A concise description of the query
     */
    private function formatQuery(string $sql): string
    {
        $type = $this->getQueryType($sql);
        $table = $this->extractTableName($sql);
        return $table ? "{$type} {$table}" : $type;
    }

    private function getQueryType(string $sql): string
    {
        $sql = trim(strtoupper($sql));
        return match (true) {
            str_starts_with($sql, 'SELECT') => 'SELECT',
            str_starts_with($sql, 'INSERT') => 'INSERT',
            str_starts_with($sql, 'UPDATE') => 'UPDATE',
            str_starts_with($sql, 'DELETE') => 'DELETE',
            str_starts_with($sql, 'CREATE') => 'CREATE',
            str_starts_with($sql, 'ALTER') => 'ALTER',
            str_starts_with($sql, 'DROP') => 'DROP',
            str_starts_with($sql, 'TRUNCATE') => 'TRUNCATE',
            str_starts_with($sql, 'REPLACE') => 'REPLACE',
            default => 'QUERY'
        };
    }


    private function extractTableName(string $sql): ?string
    {
        $tables = $this->extractTableNames($sql);
        return !empty($tables) ? $tables[0] : null;
    }

    private function extractTableNames(string $sql): array
    {
        $tables = [];
        $patterns = [
            '/(?:FROM|INTO|UPDATE|JOIN)\s+[`"]?(\w+)[`"]?/i',
            '/CREATE\s+TABLE\s+[`"]?(\w+)[`"]?/i',
            '/ALTER\s+TABLE\s+[`"]?(\w+)[`"]?/i',
            '/DROP\s+TABLE\s+[`"]?(\w+)[`"]?/i',
            '/TRUNCATE\s+TABLE\s+[`"]?(\w+)[`"]?/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $sql, $matches)) {
                foreach ($matches[1] as $table) {
                    if (!in_array($table, $tables)) {
                        $tables[] = $table;
                    }
                }
            }
        }
        return $tables;
    }

    /**
     * Set database context from QueryExecuted event (fallback)
     *
     * This method is similar to setDatabaseContext but uses a QueryExecuted event
     * as the source of information. It's used as a fallback when a query is detected
     * after it has already executed.
     *
     * @param SpanInterface $span The span to set context on
     * @param QueryExecuted $event The query executed event
     * @param string $dbSubtype The database subtype (postgresql, mysql, etc.)
     */
    private function setDatabaseContextFromEvent(SpanInterface $span, QueryExecuted $event, string $dbSubtype): void
    {
        $spanContext = $span->context();
        $database = $event->connection->getConfig('database');
        $username = $event->connection->getConfig('username');

        $span->context()->db()->setStatement($this->sanitizeQuery($event->sql));

        $spanContext->setLabel('db.instance', $database);
        $spanContext->setLabel('db.name', $database);
        $spanContext->setLabel('db.statement', $this->sanitizeQuery($event->sql));
        $spanContext->setLabel('db.type', 'sql');
        $spanContext->setLabel('db.user', $username);
        $spanContext->setLabel('db.system', $dbSubtype);
        $spanContext->setLabel('db.duration.ms', (string)$event->time);

        $queryType = $this->getQueryType($event->sql);
        $tableName = $this->extractTableName($event->sql);

        $spanContext->setLabel('db.operation', strtolower($queryType));
        if ($tableName) {
            $spanContext->setLabel('db.sql.table', $tableName);
        }

        $spanContext->setLabel('db.query.parameter_count', (string)count($event->bindings));

        $span->context()->destination()->setService($dbSubtype, $database, 'db');
    }

    /**
     * Sanitize a SQL query by truncating it if it's too long
     *
     * This prevents excessively large queries from consuming too much memory
     * or bandwidth when sent to the APM server.
     *
     * @param string $sql The SQL query to sanitize
     * @return string The sanitized SQL query
     */
    private function sanitizeQuery(string $sql): string
    {
        return strlen($sql) > 10000 ? substr($sql, 0, 10000) . '... [TRUNCATED]' : $sql;
    }

    private function addEventSpanTags(OctaneApmManager $manager, SpanInterface $span, QueryExecuted $event, string $dbSubtype, bool $isEloquent = false): void
    {
        $queryType = $this->getQueryType($event->sql);
        $this->addCommonSpanTags($manager, $span, $dbSubtype, $queryType, $isEloquent);

        $manager->addSpanTag($span, 'db.duration.ms', (string)$event->time);

        if ($event->time > 1000) {
            $manager->addSpanTag($span, 'db.slow_query', 'true');
        }
    }

    private function addCommonSpanTags(
        OctaneApmManager $manager,
        SpanInterface    $span,
        string           $dbSubtype,
        string           $operation,
        bool             $isEloquent = false,
    ): void
    {
        $manager->addSpanTag($span, 'component', $dbSubtype);
        $manager->addSpanTag($span, 'span.kind', 'client');
        $manager->addSpanTag($span, 'db.operation', strtolower($operation));

        if ($isEloquent) {
            $manager->addSpanTag($span, 'db.framework', 'eloquent');
            $this->addEloquentRelationTags($manager, $span);
        }
    }

    public function beforeQueryExecution(string $sql, array $bindings, PostgresEnhancedConnection $connection): void
    {
        if (!config('apm.monitoring.database', true)) {
            return;
        }

        $queryId = $this->generateQueryId($sql, $bindings);
        $startTime = microtime(true);

        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);
            $needsTransaction = $manager->hasNoTransactionInstance();
            $transaction = null;

            if ($needsTransaction) {
                $transactionName = $this->formatQuery($sql);
                $transaction = $manager->beginTransaction($transactionName, 'request');

                if (!$transaction) {
                    return;
                }
            }

            $dbSubtype = self::DB_SUBTYPE_POSTGRESQL;
            $eloquentInfo = $this->getEloquentInfo();

            $spanInfo = $this->createQuerySpan($manager, $sql, $dbSubtype, $eloquentInfo);
            $span = $spanInfo['span'];
            $groupKey = $spanInfo['group_key'];
            $isEloquent = $spanInfo['is_eloquent'];

            if ($span) {
                $this->setDatabaseContext($span, $sql, $bindings, $connection->getName(), $dbSubtype);
                $this->addSpanTags($manager, $span, $sql, $dbSubtype, $isEloquent);

                // Store query info for completion
                $this->activeQueries[$queryId] = [
                    'span'                  => $span,
                    'manager'               => $manager,
                    'start_time'            => $startTime,
                    'transaction'           => $transaction,
                    'needs_transaction_end' => $needsTransaction,
                    'sql'                   => $sql,
                    'connection'            => $connection->getName(),
                    'is_eloquent'           => $isEloquent,
                    'eloquent_group_key'    => $groupKey,
                ];
            }

        } catch (\Throwable $e) {
            $this->logger?->error('Failed to start query APM tracking', [
                'exception' => $e->getMessage(),
                'sql'       => $sql,
            ]);
        }
    }

    /**
     * Set database context for pre-execution span
     *
     * This method adds database-specific information to a span, including the database name,
     * username, SQL statement, query type, table name, and parameter count. This information
     * is used by the APM system to provide detailed insights into database operations.
     *
     * @param SpanInterface $span The span to set context on
     * @param string $sql The SQL query
     * @param array $bindings The query bindings
     * @param string $connectionName The database connection name
     * @param string $dbSubtype The database subtype (postgresql, mysql, etc.)
     */
    private function setDatabaseContext(SpanInterface $span, string $sql, array $bindings, string $connectionName, string $dbSubtype): void
    {
        $connection = DB::connection($connectionName);
        $spanContext = $span->context();

        $database = $connection->getConfig('database');
        $username = $connection->getConfig('username');

        $span->context()->db()->setStatement($this->sanitizeQuery($sql));

        $spanContext->setLabel('db.instance', $database);
        $spanContext->setLabel('db.name', $database);
        $spanContext->setLabel('db.statement', $this->sanitizeQuery($sql));
        $spanContext->setLabel('db.type', 'sql');
        $spanContext->setLabel('db.user', $username);
        $spanContext->setLabel('db.system', $dbSubtype);

        $queryType = $this->getQueryType($sql);
        $tableName = $this->extractTableName($sql);

        $spanContext->setLabel('db.operation', strtolower($queryType));
        if ($tableName) {
            $spanContext->setLabel('db.sql.table', $tableName);
        }

        $spanContext->setLabel('db.query.parameter_count', (string)count($bindings));

        $span->context()->destination()->setService($dbSubtype, $database, 'db');
    }

    /**
     * Add span tags for pre-execution
     */
    private function addSpanTags(OctaneApmManager $manager, SpanInterface $span, string $sql, string $dbSubtype, bool $isEloquent = false): void
    {
        $queryType = $this->getQueryType($sql);
        $this->addCommonSpanTags($manager, $span, $dbSubtype, $queryType, $isEloquent);
    }
}
