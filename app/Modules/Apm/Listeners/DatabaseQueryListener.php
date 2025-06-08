<?php

namespace App\Modules\Apm\Listeners;

use App\Modules\Apm\OctaneApmManager;
use Elastic\Apm\SpanInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

class DatabaseQueryListener
{
    private array $queryStartTimes = [];

    public function __construct(
        private ?LoggerInterface $logger = null,
    )
    {
    }

    /**
     * Register database query listeners
     */
    public function register(): void
    {
        // Hook into query execution at the connection level
        $this->hookIntoQueryExecution();

        // Also listen to QueryExecuted for additional context
        DB::listen(function ($event) {
            $this->handleQueryExecuted($event);
        });
    }

    /**
     * Hook into query execution by extending database connections
     */
    private function hookIntoQueryExecution(): void
    {
        // Get all configured connections and wrap them
        foreach (config('database.connections', []) as $name => $config) {
            DB::purge($name); // Clear any cached connections

            // When a connection is requested, wrap it
            $this->wrapConnectionQueries($name);
        }
    }

    /**
     * Wrap connection queries to add timing
     */
    private function wrapConnectionQueries(string $connectionName): void
    {
        // Listen for when this connection is used
        DB::connection($connectionName)->listen(function ($query) use ($connectionName) {
            // This fires after query execution, but we can still get timing from the query object
            $this->handleConnectionQuery($query, $connectionName);
        });

        // Use macro to extend the connection with our timing logic
        Connection::macro('runQueryCallbackWithTiming', function ($query, $bindings, \Closure $callback) use ($connectionName) {
            return $this->executeQueryWithTiming($query, $bindings, $callback, $connectionName);
        });
    }

    /**
     * Handle connection query (fallback method)
     */
    private function handleConnectionQuery($query, string $connectionName): void
    {
        // This is a fallback for queries that didn't go through our timing wrapper
        // We'll create a span here but timing won't be as accurate
        if (!config('apm.monitoring.database', true)) {
            return;
        }

        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            $dbSubtype = $this->mapDatabaseSubtype($connectionName);

            $span = $manager->createSpan(
                $this->formatQuery($query->sql),
                'db',
                $dbSubtype,
                'query',
            );

            if ($span) {
                $this->setDatabaseContextForDependencies($span, $query, $dbSubtype, $connectionName);
                $this->addSpanTags($manager, $span, $query, $dbSubtype, $connectionName);
                $span->setOutcome('success');
                $span->end();
            }

        } catch (\Throwable $e) {
            $this->logger?->error('Failed to create database span', [
                'exception' => $e->getMessage(),
                'sql'       => $query->sql ?? 'unknown',
            ]);
        }
    }

    private function mapDatabaseSubtype(string $connectionName): string
    {
        $driver = config("database.connections.$connectionName.driver", 'unknown');

        return match ($driver) {
            'pgsql' => 'postgresql',
            'mysql' => 'mysql',
            'mariadb' => 'mariadb',
            'sqlite' => 'sqlite',
            'sqlsrv' => 'mssql',
            default => 'unknown'
        };
    }

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
        $patterns = [
            '/(?:FROM|INTO|UPDATE|JOIN)\s+[`"]?(\w+)[`"]?/i',
            '/CREATE\s+TABLE\s+[`"]?(\w+)[`"]?/i',
            '/ALTER\s+TABLE\s+[`"]?(\w+)[`"]?/i',
            '/DROP\s+TABLE\s+[`"]?(\w+)[`"]?/i',
            '/TRUNCATE\s+TABLE\s+[`"]?(\w+)[`"]?/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sql, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }

    /**
     * Set database context specifically for service map dependencies
     */
    private function setDatabaseContextForDependencies(SpanInterface $span, $event, string $dbSubtype, ?string $connectionName = null): void
    {
        $connection = $connectionName ? DB::connection($connectionName) : $event->connection;
        $database = $connection->getConfig('database');
        $username = $connection->getConfig('username');

        try {
            $spanContext = $span->context();

            // Set the span outcome first
            $span->setOutcome('success');

            // Set database-specific context
            $this->setDatabaseSpecificContext($spanContext, $span, $event, $database, $username, $dbSubtype);

            // Set service target context
            $this->setServiceTargetContext($span, $dbSubtype, $database);

            $this->setPerformanceContext($spanContext, $event);

        } catch (\Throwable $e) {
            $this->logger?->warning('Failed to set database context for dependencies', [
                'exception' => $e->getMessage(),
                'database'  => $database,
                'subtype'   => $dbSubtype,
            ]);
        }
    }

    /**
     * Set database-specific context
     */
    private function setDatabaseSpecificContext($spanContext, SpanInterface $span, $event, string $database, string $username, string $dbSubtype): void
    {
        $sql = is_object($event) && property_exists($event, 'sql') ? $event->sql : (is_string($event) ? $event : '');
        $statement = $this->sanitizeQuery($sql);

        // Set database labels
        $span->context()->db()->setStatement($statement);

        $spanContext->setLabel('db.instance', $database);
        $spanContext->setLabel('db.name', $database);
        $spanContext->setLabel('db.statement', $statement);
        $spanContext->setLabel('db.type', 'sql');
        $spanContext->setLabel('db.user', $username);
        $spanContext->setLabel('db.system', $dbSubtype);

        // Database operation context
        $queryType = $this->getQueryType($sql);
        $tableName = $this->extractTableName($sql);

        $spanContext->setLabel('db.operation', strtolower($queryType));
        if ($tableName) {
            $spanContext->setLabel('db.sql.table', $tableName);
        }
    }

    private function sanitizeQuery(string $sql): string
    {
        return strlen($sql) > 10000 ? substr($sql, 0, 10000) . '... [TRUNCATED]' : $sql;
    }

    /**
     * Estimate query complexity before execution
     */
    private function estimateQueryComplexity(string $sql): string
    {
        $sql = strtoupper($sql);
        $complexity = 0;

        // Count joins
        $complexity += substr_count($sql, 'JOIN') * 2;
        $complexity += substr_count($sql, 'LEFT JOIN') * 2;
        $complexity += substr_count($sql, 'RIGHT JOIN') * 2;
        $complexity += substr_count($sql, 'INNER JOIN') * 2;

        // Count subqueries
        $complexity += substr_count($sql, '(SELECT') * 3;

        // Count aggregations
        $complexity += substr_count($sql, 'GROUP BY') * 2;
        $complexity += substr_count($sql, 'ORDER BY') * 1;
        $complexity += substr_count($sql, 'HAVING') * 2;

        // Count functions
        $complexity += substr_count($sql, 'COUNT(') * 1;
        $complexity += substr_count($sql, 'SUM(') * 1;
        $complexity += substr_count($sql, 'AVG(') * 1;

        return match (true) {
            $complexity >= 10 => 'very_high',
            $complexity >= 7 => 'high',
            $complexity >= 4 => 'medium',
            $complexity >= 2 => 'low',
            default => 'very_low'
        };
    }

    /**
     * Estimate query duration before execution
     */
    private function estimateQueryDuration(string $sql): float
    {
        $complexity = $this->estimateQueryComplexity($sql);
        $queryType = $this->getQueryType($sql);

        $baseTime = match ($queryType) {
            'SELECT' => 10.0,
            'INSERT' => 5.0,
            'UPDATE' => 8.0,
            'DELETE' => 7.0,
            default => 15.0
        };

        $multiplier = match ($complexity) {
            'very_high' => 10.0,
            'high' => 5.0,
            'medium' => 2.0,
            'low' => 1.2,
            default => 1.0
        };

        return $baseTime * $multiplier; // Return estimated milliseconds
    }

    /**
     * Predict resource usage
     */
    private function predictResourceUsage(string $sql): array
    {
        $complexity = $this->estimateQueryComplexity($sql);
        $queryType = $this->getQueryType($sql);

        $baseMemory = match ($queryType) {
            'SELECT' => 2.0,
            'INSERT' => 1.0,
            'UPDATE' => 1.5,
            'DELETE' => 1.2,
            default => 2.5
        };

        $memoryMultiplier = match ($complexity) {
            'very_high' => 8.0,
            'high' => 4.0,
            'medium' => 2.0,
            'low' => 1.2,
            default => 1.0
        };

        $baseIoOps = match ($queryType) {
            'SELECT' => 5,
            'INSERT' => 2,
            'UPDATE' => 4,
            'DELETE' => 3,
            default => 6
        };

        return [
            'memory_mb' => $baseMemory * $memoryMultiplier,
            'io_ops' => $baseIoOps * (int) $memoryMultiplier,
            'cost_score' => ($baseMemory * $memoryMultiplier) + ($baseIoOps * $memoryMultiplier / 10)
        ];
    }

    /**
     * Calculate performance score after execution
     */
    private function calculatePerformanceScore(string $sql, float $actualTime): int
    {
        $expectedTime = $this->estimateQueryDuration($sql) / 1000; // Convert to seconds

        if ($actualTime <= $expectedTime * 0.5) {
            return 100; // Excellent
        } elseif ($actualTime <= $expectedTime) {
            return 80; // Good
        } elseif ($actualTime <= $expectedTime * 2) {
            return 60; // Acceptable
        } elseif ($actualTime <= $expectedTime * 5) {
            return 40; // Poor
        } else {
            return 20; // Critical
        }
    }

    /**
     * Get optimization suggestions
     */
    private function getOptimizationSuggestions(string $sql, float $actualTime): array
    {
        $suggestions = [];

        if ($actualTime > 1.0) {
            $suggestions[] = 'Consider adding database indexes';
        }

        if (stripos($sql, 'SELECT *') !== false) {
            $suggestions[] = 'Avoid SELECT * - specify needed columns';
        }

        if (substr_count(strtoupper($sql), 'JOIN') > 3) {
            $suggestions[] = 'Consider breaking down complex joins';
        }

        if (stripos($sql, 'ORDER BY') !== false && stripos($sql, 'LIMIT') === false) {
            $suggestions[] = 'Add LIMIT clause to ORDER BY queries';
        }

        if (stripos($sql, 'LIKE \'%') !== false) {
            $suggestions[] = 'Avoid leading wildcards in LIKE queries';
        }

        if (stripos($sql, 'WHERE') === false && $this->getQueryType($sql) === 'SELECT') {
            $suggestions[] = 'Consider adding WHERE clause to limit results';
        }

        return $suggestions;
    }

    /**
     * Get performance tier
     */
    private function getPerformanceTier(float $durationMs): string
    {
        return match (true) {
            $durationMs < 10 => 'excellent',
            $durationMs < 100 => 'good',
            $durationMs < 1000 => 'acceptable',
            $durationMs < 5000 => 'slow',
            default => 'critical'
        };
    }

    /**
     * Check if query is a read operation
     */
    private function isReadOperation(string $sql): bool
    {
        $queryType = $this->getQueryType($sql);
        return in_array($queryType, ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN']);
    }

    /**
     * Check if query is a write operation
     */
    private function isWriteOperation(string $sql): bool
    {
        $queryType = $this->getQueryType($sql);
        return in_array($queryType, ['INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE']);
    }

    /**
     * Get query classification
     */
    private function getQueryClassification(string $sql): array
    {
        $queryType = $this->getQueryType($sql);
        $complexity = $this->estimateQueryComplexity($sql);
        $isRead = $this->isReadOperation($sql);
        $isWrite = $this->isWriteOperation($sql);

        return [
            'type' => strtolower($queryType),
            'complexity' => $complexity,
            'is_read' => $isRead,
            'is_write' => $isWrite,
            'category' => $isRead ? 'read' : ($isWrite ? 'write' : 'other'),
            'has_joins' => stripos($sql, 'JOIN') !== false,
            'has_subqueries' => stripos($sql, '(SELECT') !== false,
            'has_aggregations' => (
                stripos($sql, 'GROUP BY') !== false ||
                stripos($sql, 'COUNT(') !== false ||
                stripos($sql, 'SUM(') !== false ||
                stripos($sql, 'AVG(') !== false
            )
        ];
    }

    /**
     * Format duration for human readability
     */
    private function formatDuration(float $milliseconds): string
    {
        if ($milliseconds < 1) {
            return round($milliseconds * 1000, 2) . 'μs';
        } elseif ($milliseconds < 1000) {
            return round($milliseconds, 2) . 'ms';
        } else {
            return round($milliseconds / 1000, 2) . 's';
        }
    }

    /**
     * Get query fingerprint (normalized version for grouping)
     */
    private function getQueryFingerprint(string $sql): string
    {
        // Remove values and normalize for grouping similar queries
        $fingerprint = preg_replace([
            '/\b\d+\b/',           // Numbers
            '/\'[^\']*\'/',        // String literals
            '/\s+/',               // Multiple spaces
            '/\(\s*\)/',           // Empty parentheses
        ], [
            '?',
            '?',
            ' ',
            '()',
        ], $sql);

        return trim(strtoupper($fingerprint));
    }

    /**
     * Extract affected rows estimation
     */
    private function estimateAffectedRows(string $sql): ?int
    {
        // This is a rough estimation - in practice you'd need more sophisticated analysis
        $queryType = $this->getQueryType($sql);

        if (in_array($queryType, ['SELECT', 'UPDATE', 'DELETE'])) {
            if (stripos($sql, 'LIMIT') !== false) {
                if (preg_match('/LIMIT\s+(\d+)/i', $sql, $matches)) {
                    return (int) $matches[1];
                }
            }

            if (stripos($sql, 'WHERE') === false && $queryType !== 'SELECT') {
                return 1000; // Rough estimate for queries without WHERE clause
            }
        }

        return null; // Cannot estimate
    }

    /**
     * Check if query uses indexes (basic heuristic)
     */
    private function likelyUsesIndexes(string $sql): bool
    {
        // Basic heuristics - in practice you'd analyze the query plan
        $hasWhere = stripos($sql, 'WHERE') !== false;
        $hasOrderBy = stripos($sql, 'ORDER BY') !== false;
        $hasGroupBy = stripos($sql, 'GROUP BY') !== false;

        // Likely uses indexes if it has WHERE with common indexed patterns
        if ($hasWhere) {
            $hasEquality = preg_match('/WHERE\s+\w+\s*=/', $sql);
            $hasIn = stripos($sql, ' IN (') !== false;
            $hasBetween = stripos($sql, ' BETWEEN ') !== false;

            if ($hasEquality || $hasIn || $hasBetween) {
                return true;
            }
        }

        return $hasOrderBy || $hasGroupBy;
    }

    /**
     * Set service target context
     */
    private function setServiceTargetContext(SpanInterface $span, string $dbSubtype, string $database): void
    {
        $span->context()->destination()->setService($dbSubtype, $database, 'db');
    }

    /**
     * Set performance context
     */
    private function setPerformanceContext($spanContext, $event): void
    {
        $durationMs = is_object($event) && property_exists($event, 'time') ? $event->time : 0;
        $durationUs = round($durationMs * 1000);

        // Timing metrics
        $spanContext->setLabel('db.duration.ms', (string)$durationMs);
        $spanContext->setLabel('db.duration.us', (string)$durationUs);
        $spanContext->setLabel('db.response.duration', (string)$durationMs);

        // Performance classification
        $spanContext->setLabel('db.slow_query', $durationMs > 1000 ? 'true' : 'false');

        // Query size metrics
        $sql = is_object($event) && property_exists($event, 'sql') ? $event->sql : '';
        $spanContext->setLabel('db.query.size_bytes', (string)strlen($sql));
    }

    private function addSpanTags(OctaneApmManager $manager, SpanInterface $span, $event, string $dbSubtype, ?string $connectionName = null): void
    {
        $connection = $connectionName ? DB::connection($connectionName) : $event->connection;
        $database = $connection->getConfig('database');
        $sql = is_object($event) && property_exists($event, 'sql') ? $event->sql : '';
        $time = is_object($event) && property_exists($event, 'time') ? $event->time : 0;

        $queryType = $this->getQueryType($sql);
        $tableName = $this->extractTableName($sql);

        // Performance metrics
        $manager->addSpanTag($span, 'db.duration.us', (string)round($time * 1000));

        if ($time > 1000) {
            $manager->addSpanTag($span, 'db.slow_query', 'true');
        }

        $span->context()->destination()->setService(
            name: $dbSubtype,
            resource: $database,
            type: 'db',
        );

        // Classification tags for better APM categorization
        $manager->addSpanTag($span, 'component', $dbSubtype);
        $manager->addSpanTag($span, 'span.kind', 'client');
        $manager->addSpanTag($span, 'peer.service', $dbSubtype);
        $manager->addSpanTag($span, 'peer.hostname', $connection->getConfig('host'));

        // Resource identification
        $manager->addSpanTag($span, 'resource.name', $database);
        $manager->addSpanTag($span, 'resource.type', 'database');
        $manager->addSpanTag($span, 'resource.operation', strtolower($queryType));
        $manager->addSpanTag($span, 'resource.database.name', $database);
        $manager->addSpanTag($span, 'resource.database.type', $dbSubtype);
        $manager->addSpanTag($span, 'resource.database.table', $tableName);
    }

    /**
     * Execute query with proper timing
     */
    public function executeQueryWithTiming(string $query, array $bindings, \Closure $callback, string $connectionName)
    {
        if (!config('apm.monitoring.database', true)) {
            return $callback();
        }

        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        $queryId = uniqid('db_', true);

        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            // Check if we have an active transaction
            $needsTransaction = $manager->hasNoTransactionInstance();
            $transaction = null;

            if ($needsTransaction) {
                $transactionName = $this->formatQuery($query);
                $transaction = $manager->beginTransaction($transactionName, 'request');

                if (!$transaction) {
                    return $callback();
                }

                $this->addTransactionContext($manager, $query, $connectionName);
            }

            // Get database subtype
            $dbSubtype = $this->mapDatabaseSubtype($connectionName);

            // Create and start the span
            $span = $manager->createSpan(
                $this->formatQuery($query),
                'db',
                $dbSubtype,
                'query',
            );

            if ($span) {
                // Set initial context
                $this->setPreExecutionContext($span, $query, $bindings, $connectionName, $dbSubtype);
                $this->addPreExecutionTags($manager, $span, $query, $dbSubtype);

                // Store for cleanup
                $this->queryStartTimes[$queryId] = [
                    'span'                  => $span,
                    'manager'               => $manager,
                    'start_time'            => $startTime,
                    'start_memory'          => $startMemory,
                    'needs_transaction_end' => $needsTransaction,
                ];
            }

            // Execute the query
            $result = $callback();

            // Calculate timing
            $actualTime = microtime(true) - $startTime;
            $memoryUsed = memory_get_usage(true) - $startMemory;

            if ($span) {
                // Set post-execution context
                $this->setPostExecutionContext($span, $query, $actualTime, $memoryUsed, true);
                $this->addPostExecutionTags($manager, $span, $query, $actualTime, true);

                $span->setOutcome('success');
                $span->end();

                // End transaction if needed
                if ($needsTransaction && $transaction) {
                    $manager->setTransactionResult('success');
                    $manager->setTransactionOutcome('success');
                    $manager->endTransaction();
                }
            }

            unset($this->queryStartTimes[$queryId]);
            return $result;

        } catch (\Throwable $e) {
            $actualTime = microtime(true) - $startTime;

            if (isset($this->queryStartTimes[$queryId])) {
                $span = $this->queryStartTimes[$queryId]['span'];
                $manager = $this->queryStartTimes[$queryId]['manager'];
                $needsTransactionEnd = $this->queryStartTimes[$queryId]['needs_transaction_end'];

                $this->setPostExecutionContext($span, $query, $actualTime, 0, false);
                $this->addPostExecutionTags($manager, $span, $query, $actualTime, false);

                $span->setOutcome('failure');
                $manager->recordException($e);
                $span->end();

                if ($needsTransactionEnd) {
                    $manager->setTransactionResult('error');
                    $manager->setTransactionOutcome('failure');
                    $manager->endTransaction();
                }

                unset($this->queryStartTimes[$queryId]);
            }

            throw $e;
        }
    }

    private function addTransactionContext(OctaneApmManager $manager, string $sql, string $connectionName): void
    {
        $connection = DB::connection($connectionName);
        $dbSubtype = $this->mapDatabaseSubtype($connectionName);
        $classification = $this->getQueryClassification($sql);
        $complexity = $this->estimateQueryComplexity($sql);

        $manager->addCustomTag('db.type', 'sql');
        $manager->addCustomTag('db.instance', $connection->getConfig('database') ?? 'unknown');
        $manager->addCustomTag('db.system', $dbSubtype);
        $manager->addCustomTag('db.operation', strtolower($this->getQueryType($sql)));
        $manager->addCustomTag('db.query.complexity', $complexity);
        $manager->addCustomTag('db.query.category', $classification['category']);

        // Add high-level transaction tags
        if ($classification['has_joins']) {
            $manager->addCustomTag('transaction.has_complex_queries', 'true');
        }

        if ($complexity === 'very_high') {
            $manager->addCustomTag('transaction.has_expensive_queries', 'true');
        }
    }

    private function setPreExecutionContext(SpanInterface $span, string $sql, array $bindings, string $connectionName, string $dbSubtype): void
    {
        $connection = DB::connection($connectionName);
        $spanContext = $span->context();

        // Basic database context
        $database = $connection->getConfig('database');
        $username = $connection->getConfig('username');

        $span->context()->db()->setStatement($this->sanitizeQuery($sql));

        $spanContext->setLabel('db.instance', $database);
        $spanContext->setLabel('db.name', $database);
        $spanContext->setLabel('db.statement', $this->sanitizeQuery($sql));
        $spanContext->setLabel('db.type', 'sql');
        $spanContext->setLabel('db.user', $username);
        $spanContext->setLabel('db.system', $dbSubtype);

        // Use helper methods for query analysis
        $queryType = $this->getQueryType($sql);
        $tableName = $this->extractTableName($sql);
        $complexity = $this->estimateQueryComplexity($sql);
        $classification = $this->getQueryClassification($sql);
        $fingerprint = $this->getQueryFingerprint($sql);
        $estimatedDuration = $this->estimateQueryDuration($sql);
        $resourcePrediction = $this->predictResourceUsage($sql);
        $estimatedRows = $this->estimateAffectedRows($sql);

        // Query metadata
        $spanContext->setLabel('db.operation', strtolower($queryType));
        $spanContext->setLabel('db.query.type', strtolower($queryType));
        $spanContext->setLabel('db.query.complexity', $complexity);
        $spanContext->setLabel('db.query.hash', hash('sha256', $sql));
        $spanContext->setLabel('db.query.fingerprint', hash('md5', $fingerprint));
        $spanContext->setLabel('db.query.planned_at', date('c'));

        if ($tableName) {
            $spanContext->setLabel('db.sql.table', $tableName);
        }

        // Classification labels
        $spanContext->setLabel('db.query.category', $classification['category']);
        $spanContext->setLabel('db.query.is_read', $classification['is_read'] ? 'true' : 'false');
        $spanContext->setLabel('db.query.is_write', $classification['is_write'] ? 'true' : 'false');
        $spanContext->setLabel('db.query.has_joins', $classification['has_joins'] ? 'true' : 'false');
        $spanContext->setLabel('db.query.has_subqueries', $classification['has_subqueries'] ? 'true' : 'false');

        // Predictions
        $spanContext->setLabel('db.query.expected_duration_ms', (string) $estimatedDuration);
        $spanContext->setLabel('db.query.predicted_memory_mb', (string) $resourcePrediction['memory_mb']);
        $spanContext->setLabel('db.query.predicted_io_ops', (string) $resourcePrediction['io_ops']);
        $spanContext->setLabel('db.query.cost_score', (string) $resourcePrediction['cost_score']);
        $spanContext->setLabel('db.query.likely_uses_indexes', $this->likelyUsesIndexes($sql) ? 'true' : 'false');

        if ($estimatedRows !== null) {
            $spanContext->setLabel('db.query.estimated_rows', (string) $estimatedRows);
        }

        // Binding context
        $spanContext->setLabel('db.query.parameter_count', (string) count($bindings));
        $spanContext->setLabel('db.query.has_parameters', count($bindings) > 0 ? 'true' : 'false');

        // Service target
        $span->context()->destination()->setService($dbSubtype, $database, 'db');
    }

    private function addPreExecutionTags(OctaneApmManager $manager, SpanInterface $span, string $sql, string $dbSubtype): void
    {
        $queryType = $this->getQueryType($sql);
        $complexity = $this->estimateQueryComplexity($sql);
        $classification = $this->getQueryClassification($sql);

        $manager->addSpanTag($span, 'component', $dbSubtype);
        $manager->addSpanTag($span, 'span.kind', 'client');
        $manager->addSpanTag($span, 'db.operation', strtolower($queryType));
        $manager->addSpanTag($span, 'db.query.complexity', $complexity);
        $manager->addSpanTag($span, 'db.query.category', $classification['category']);

        // Add tags for filtering in APM
        if ($classification['has_joins']) {
            $manager->addSpanTag($span, 'db.has_joins', 'true');
        }

        if ($classification['has_subqueries']) {
            $manager->addSpanTag($span, 'db.has_subqueries', 'true');
        }

        if (!$this->likelyUsesIndexes($sql)) {
            $manager->addSpanTag($span, 'db.potential_full_scan', 'true');
        }
    }

    private function setPostExecutionContext(SpanInterface $span, string $sql, float $actualTime, int $memoryUsed, bool $success): void
    {
        $spanContext = $span->context();

        // Actual execution metrics
        $actualTimeMs = $actualTime * 1000;
        $spanContext->setLabel('db.duration.ms', (string) $actualTimeMs);
        $spanContext->setLabel('db.duration.us', (string) ($actualTime * 1000000));
        $spanContext->setLabel('db.query.actual_duration_ms', (string) $actualTimeMs);
        $spanContext->setLabel('db.query.actual_memory_bytes', (string) $memoryUsed);
        $spanContext->setLabel('db.query.completed_at', date('c'));
        $spanContext->setLabel('db.query.formatted_duration', $this->formatDuration($actualTimeMs));

        // Performance analysis using helper methods
        $performanceScore = $this->calculatePerformanceScore($sql, $actualTime);
        $performanceTier = $this->getPerformanceTier($actualTimeMs);

        $spanContext->setLabel('db.query.performance_score', (string) $performanceScore);
        $spanContext->setLabel('db.performance.tier', $performanceTier);
        $spanContext->setLabel('db.slow_query', $actualTimeMs > 1000 ? 'true' : 'false');

        // Success/failure context
        $spanContext->setLabel('db.query.success', $success ? 'true' : 'false');

        // Optimization suggestions for slower queries
        if ($actualTimeMs > 100) { // Only for queries over 100ms
            $suggestions = $this->getOptimizationSuggestions($sql, $actualTime);
            if (!empty($suggestions)) {
                $spanContext->setLabel('db.query.optimization_suggestions', json_encode($suggestions));
                $spanContext->setLabel('db.query.optimization_count', (string) count($suggestions));
            }
        }

        // Performance comparison
        $expectedDuration = $this->estimateQueryDuration($sql);
        $performanceRatio = $expectedDuration > 0 ? ($actualTimeMs / $expectedDuration) : 1.0;
        $spanContext->setLabel('db.query.performance_ratio', (string) round($performanceRatio, 2));

        if ($performanceRatio > 2.0) {
            $spanContext->setLabel('db.query.slower_than_expected', 'true');
        } elseif ($performanceRatio < 0.5) {
            $spanContext->setLabel('db.query.faster_than_expected', 'true');
        }
    }

    private function addPostExecutionTags(OctaneApmManager $manager, SpanInterface $span, string $sql, float $actualTime, bool $success): void
    {
        $actualTimeMs = $actualTime * 1000;
        $performanceTier = $this->getPerformanceTier($actualTimeMs);
        $performanceScore = $this->calculatePerformanceScore($sql, $actualTime);

        $manager->addSpanTag($span, 'db.duration.ms', (string) $actualTimeMs);
        $manager->addSpanTag($span, 'db.performance.tier', $performanceTier);
        $manager->addSpanTag($span, 'db.performance.score', (string) $performanceScore);
        $manager->addSpanTag($span, 'success', $success ? 'true' : 'false');

        // Critical performance tags
        if ($actualTimeMs > 1000) {
            $manager->addSpanTag($span, 'db.slow_query', 'true');
        }

        if ($performanceScore < 40) {
            $manager->addSpanTag($span, 'db.poor_performance', 'true');
        }

        // Optimization needed tags
        $suggestions = $this->getOptimizationSuggestions($sql, $actualTime);
        if (!empty($suggestions)) {
            $manager->addSpanTag($span, 'db.needs_optimization', 'true');
            $manager->addSpanTag($span, 'db.optimization_priority', count($suggestions) > 2 ? 'high' : 'medium');
        }
    }


    /**
     * Handle the QueryExecuted event
     */
    public function handleQueryExecuted(QueryExecuted $event): void
    {
        // This method handles the traditional QueryExecuted event
        // It's our main method for capturing database queries
        $this->handle($event);
    }

    /**
     * Handle the query executed event
     */
    public function handle(QueryExecuted $event): void
    {
        if (!config('apm.monitoring.database', true)) {
            return;
        }

        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            // Check if we have an active transaction, if not create one for this query
            $needsTransaction = $manager->hasNoTransactionInstance();
            $transaction = null;

            if ($needsTransaction) {
                // Start a transaction for standalone database queries
                $transactionName = $this->formatQuery($event->sql);

                $transaction = $manager->beginTransaction(
                    $transactionName,
                    'request',
                );

                if (!$transaction) {
                    return;
                }

                // Add transaction-level context
                $this->addTransactionContext($manager, $event->sql, $event->connectionName);
            }

            // Get the database driver and map to correct subtype
            $dbSubtype = $this->mapDatabaseSubtype($event->connection->getName());

            // Create the span according to official specification
            $span = $manager->createSpan(
                $this->formatQuery($event->sql),
                'db',
                $dbSubtype,
                'query',
            );

            if ($span) {
                // Set database context for service map dependencies
                $this->setDatabaseContextForDependencies($span, $event, $dbSubtype);

                // Add additional tags
                $this->addSpanTags($manager, $span, $event, $dbSubtype);

                $span->end();
            }

            // End the transaction if we created it
            if ($needsTransaction && $transaction) {
                $manager->setTransactionResult('success');
                $manager->setTransactionOutcome('success');
                $manager->endTransaction();
            }

        } catch (\Throwable $e) {
            $this->logger?->error('Failed to create database span', [
                'exception' => $e->getMessage(),
                'sql'       => $event->sql,
            ]);

            // Ensure transaction is ended even on error
            try {
                /** @var OctaneApmManager $manager */
                $manager = App::make(OctaneApmManager::class);
                if (!$manager->hasNoTransactionInstance()) {
                    $manager->setTransactionResult('error');
                    $manager->setTransactionOutcome('failure');
                    $manager->recordException($e);
                    $manager->endTransaction();
                }
            } catch (\Throwable $cleanupException) {
                $this->logger?->error('Failed to cleanup transaction after database error', [
                    'exception' => $cleanupException->getMessage(),
                ]);
            }
        }
    }

    private function buildTransactionContext($event): array
    {
        $dbSubtype = $this->mapDatabaseSubtype($event->connection->getName());

        return [
            'db'      => [
                'type'     => 'sql',
                'instance' => $event->connection->getConfig('database'),
                'user'     => $event->connection->getConfig('username'),
                'system'   => $dbSubtype,
            ],
            'service' => [
                'target' => [
                    'type' => $dbSubtype,
                    'name' => $event->connection->getConfig('database'),
                ],
            ],
        ];
    }
}