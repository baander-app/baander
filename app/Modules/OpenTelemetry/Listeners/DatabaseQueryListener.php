<?php

namespace App\Modules\OpenTelemetry\Listeners;

use App\Modules\OpenTelemetry\OpenTelemetryManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use OpenTelemetry\API\Trace\SpanInterface;

class DatabaseQueryListener
{
    private OpenTelemetryManager $telemetry;
    private array $activeQueries = [];

    public function __construct(OpenTelemetryManager $telemetry)
    {
        $this->telemetry = $telemetry;
    }

    public function register(): void
    {
        DB::beforeExecuting($this->beforeQueryExecution(...));
        DB::listen(function ($event) {
            $this->handleQueryExecuted($event);
        });
    }

    private function beforeQueryExecution(string $query, array $bindings): void
    {
        $queryId = $this->generateQueryId($query, $bindings);

        $span = $this->telemetry->startDatabaseSpan($query, DB::getDefaultConnection());

        $config = $this->getConnectionConfig(DB::getDefaultConnection());
        $operation = $this->extractSqlOperation($query);
        $table = $this->extractTableName($query);

        $this->setSpanAttributes($span, $query, $bindings, $config, $operation, $table, DB::getDefaultConnection());
        $span->setAttribute('db.query.start_time', microtime(true));

        $this->activeQueries[$queryId] = [
            'span'       => $span,
            'start_time' => microtime(true),
            'operation'  => $operation,
            'table'      => $table,
        ];
    }

    public function handleQueryExecuted(QueryExecuted $event): void
    {
        $queryId = $this->generateQueryId($event->sql, $event->bindings);

        if (isset($this->activeQueries[$queryId])) {
            $this->completeQueryTracking($queryId, $event);
        } else {
            $this->handleMissedQuery($event);
        }
    }

    private function completeQueryTracking(string $queryId, QueryExecuted $event): void
    {
        $queryData = $this->activeQueries[$queryId];
        $span = $queryData['span'];

        $span->setAttribute('db.query.end_time', microtime(true));
        $span->setAttribute('db.query.duration', $event->time);
        $span->setAttribute('db.laravel.connection_name', $event->connectionName);

        $this->recordMetrics($event, $queryData['operation'], $queryData['table']);

        if ($event->time >= 100) {
            $span->setAttribute('db.query.slow', true);
        }

        $span->end();
        unset($this->activeQueries[$queryId]);
    }

    private function handleMissedQuery(QueryExecuted $event): void
    {
        $span = $this->telemetry->startDatabaseSpan($event->sql, $event->connectionName);

        $config = $this->getConnectionConfig($event->connectionName);
        $operation = $this->extractSqlOperation($event->sql);
        $table = $this->extractTableName($event->sql);

        $this->setSpanAttributes($span, $event->sql, $event->bindings, $config, $operation, $table, $event->connectionName);
        $span->setAttribute('db.query.duration', $event->time);

        $this->recordMetrics($event, $operation, $table);

        $span->end();
    }

    private function setSpanAttributes(SpanInterface $span, string $query, array $bindings, array $config, string $operation, ?string $table, string $connectionName): void
    {
        $attributes = [
            'db.system'                  => 'postgresql',
            'db.name'                    => $config['database'] ?? '',
            'db.operation'               => $operation,
            'db.statement'               => $query,
            'db.user'                    => $config['username'] ?? '',
            'server.address'             => $config['host'] ?? '',
            'server.port'                => $config['port'] ?? 5432,
            'db.sql.table'               => $table,
            'db.query.parameter_count'   => count($bindings),
            'db.query.parameters'        => json_encode($this->anonymizeBindings($bindings)),
            'db.laravel.connection_name' => $connectionName,
        ];

        foreach ($attributes as $key => $value) {
            $span->setAttribute($key, $value);
        }
    }

    private function recordMetrics(QueryExecuted $event, string $operation, ?string $table): void
    {
        $this->telemetry->recordHistogram('db.query.duration', $event->time, [
            'db.system'          => 'postgresql',
            'db.operation'       => $operation,
            'db.connection_name' => $event->connectionName,
            'db.table'           => $table,
        ]);

        $this->telemetry->recordMetric('db.query.count', 1, [
            'db.system'          => 'postgresql',
            'db.operation'       => $operation,
            'db.connection_name' => $event->connectionName,
        ]);
    }

    private function generateQueryId(string $sql, array $bindings): string
    {
        return md5($sql . serialize($bindings));
    }

    private function getConnectionConfig(string $connectionName): array
    {
        return config("database.connections.{$connectionName}", []);
    }

    private function extractSqlOperation(string $sql): string
    {
        $sql = trim(strtolower($sql));
        return explode(' ', $sql)[0] ?? 'unknown';
    }

    private function extractTableName(string $sql): ?string
    {
        $sql = trim(strtolower($sql));

        if (preg_match('/insert\s+into\s+(?:"([^"]+)"|([a-zA-Z_][a-zA-Z0-9_]*))(?:\s|$)/i', $sql, $matches)) {
            return $matches[1] ?? $matches[2] ?? null;
        }

        if (preg_match('/(?:from|join)\s+(?:"([^"]+)"|([a-zA-Z_][a-zA-Z0-9_]*))(?:\s+as\s+\w+)?/i', $sql, $matches)) {
            return $matches[1] ?? $matches[2] ?? null;
        }

        if (preg_match('/update\s+(?:"([^"]+)"|([a-zA-Z_][a-zA-Z0-9_]*))(?:\s+set|\s|$)/i', $sql, $matches)) {
            return $matches[1] ?? $matches[2] ?? null;
        }

        if (preg_match('/delete\s+from\s+(?:"([^"]+)"|([a-zA-Z_][a-zA-Z0-9_]*))(?:\s|$)/i', $sql, $matches)) {
            return $matches[1] ?? $matches[2] ?? null;
        }

        if (preg_match('/(?:create|drop|alter)\s+table\s+(?:if\s+(?:not\s+)?exists\s+)?(?:"([^"]+)"|([a-zA-Z_][a-zA-Z0-9_]*))(?:\s|$)/i', $sql, $matches)) {
            return $matches[1] ?? $matches[2] ?? null;
        }

        if (preg_match('/truncate\s+table\s+(?:"([^"]+)"|([a-zA-Z_][a-zA-Z0-9_]*))(?:\s|$)/i', $sql, $matches)) {
            return $matches[1] ?? $matches[2] ?? null;
        }

        if (preg_match('/(?:from|into|update|join)\s+(?:"([^"]+)"\.)?(?:"([^"]+)"|([a-zA-Z_][a-zA-Z0-9_]*))(?:\s+as\s+\w+)?/i', $sql, $matches)) {
            return $matches[2] ?? $matches[3] ?? null;
        }

        return null;
    }

    private function anonymizeBindings(array $bindings): array
    {
        return array_map(function ($binding) {
            if (is_null($binding)) {
                return null;
            }

            if (is_bool($binding)) {
                return $binding;
            }

            if (is_numeric($binding)) {
                return $binding;
            }

            if (is_string($binding)) {
                if (strlen($binding) <= 3) {
                    return str_repeat('*', strlen($binding));
                }

                return substr($binding, 0, 1) . str_repeat('*', strlen($binding) - 2) . substr($binding, -1);
            }

            return '[' . gettype($binding) . ']';
        }, $bindings);
    }
}