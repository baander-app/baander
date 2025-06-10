<?php

namespace App\Modules\Apm\Spans;

/**
 * Specialized span for Eloquent ORM database queries
 *
 * This class extends QuerySpan to add Eloquent-specific functionality.
 * It provides methods for working with Eloquent query spans, including
 * getting information about the model, method, relations, and pivot models.
 */
class EloquentQuerySpan extends QuerySpan
{
    /**
     * The Eloquent model or builder class
     */
    protected ?string $modelClass = null;

    /**
     * The method being called
     */
    protected ?string $methodName = null;

    /**
     * Whether this is a relation query
     */
    protected bool $isRelation = false;

    /**
     * The name of the relation (if is_relation is true)
     */
    protected ?string $relationName = null;

    /**
     * The related model class (if available)
     */
    protected ?string $relatedModel = null;

    /**
     * A unique key for grouping related queries
     */
    protected ?string $groupKey = null;

    /**
     * Whether this is a pivot model
     */
    protected bool $isPivot = false;

    /**
     * The pivot table name (if is_pivot is true)
     */
    protected ?string $pivotTable = null;

    /**
     * The query bindings
     */
    protected ?array $bindings = null;

    /**
     * The database connection name
     */
    protected ?string $connectionName = null;

    /**
     * Eager loaded relationships
     */
    protected array $eagerLoad = [];

    /**
     * Applied query scopes
     */
    protected array $scopes = [];

    /**
     * Whether soft deletes are being used
     */
    protected bool $withTrashed = false;

    /**
     * Whether only trashed models are being queried
     */
    protected bool $onlyTrashed = false;

    /**
     * Set Eloquent information
     *
     * @param array $eloquentInfo Information about the Eloquent query
     * @return self
     */
    public function setEloquentInfo(array $eloquentInfo): self
    {
        $this->modelClass = $eloquentInfo['class'] ?? null;
        $this->methodName = $eloquentInfo['method'] ?? null;
        $this->isRelation = $eloquentInfo['is_relation'] ?? false;
        $this->relationName = $eloquentInfo['relation_name'] ?? null;
        $this->relatedModel = $eloquentInfo['related_model'] ?? null;
        $this->groupKey = $eloquentInfo['group_key'] ?? null;

        // Check if this is a pivot model
        $this->isPivot = $eloquentInfo['is_pivot'] ?? false;
        $this->pivotTable = $eloquentInfo['pivot_table'] ?? null;

        // Set additional Eloquent-specific information if available
        $this->bindings = $eloquentInfo['bindings'] ?? null;
        $this->connectionName = $eloquentInfo['connection'] ?? null;
        $this->eagerLoad = $eloquentInfo['eager_load'] ?? [];
        $this->scopes = $eloquentInfo['scopes'] ?? [];
        $this->withTrashed = $eloquentInfo['with_trashed'] ?? false;
        $this->onlyTrashed = $eloquentInfo['only_trashed'] ?? false;

        // If we have a model class but no pivot info, try to detect if it's a pivot
        if ($this->modelClass && !$this->isPivot) {
            // Check if the model extends BasePivot or Pivot
            if (
                is_subclass_of($this->modelClass, 'App\\Models\\BasePivot') ||
                is_subclass_of($this->modelClass, 'Illuminate\\Database\\Eloquent\\Relations\\Pivot')
            ) {
                $this->isPivot = true;

                // Try to get the table name from the model
                try {
                    $model = new $this->modelClass;
                    $this->pivotTable = $model->getTable();
                } catch (\Throwable $e) {
                    // If we can't instantiate the model, try to guess the table name
                    $className = substr($this->modelClass, strrpos($this->modelClass, '\\') + 1);
                    $this->pivotTable = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className));
                }
            }
        }

        // Try to detect eager loading from the SQL query
        if (empty($this->eagerLoad) && $this->getSql()) {
            $this->detectEagerLoading();
        }

        // Try to detect soft deletes from the SQL query
        if (!$this->withTrashed && !$this->onlyTrashed && $this->getSql()) {
            $this->detectSoftDeletes();
        }

        return $this;
    }

    /**
     * Detect eager loading from the SQL query
     *
     * This method analyzes the SQL query to detect eager loading patterns
     * and populates the eagerLoad property with the detected relationships.
     */
    protected function detectEagerLoading(): void
    {
        $sql = $this->getSql();

        if (!$sql) {
            return;
        }

        // Look for JOIN patterns that indicate eager loading
        if (preg_match_all('/JOIN\s+([`"]?\w+[`"]?)\s+(?:AS\s+)?([`"]?\w+[`"]?)\s+ON/i', $sql, $matches)) {
            for ($i = 0; $i < count($matches[0]); $i++) {
                $table = trim($matches[1][$i], '`"');
                $alias = trim($matches[2][$i], '`"');

                // If the alias is different from the table, it might be a relationship
                if ($table !== $alias && !in_array($table, $this->eagerLoad)) {
                    // Convert table name to relationship name (simple heuristic)
                    $relationship = $this->tableToRelationshipName($table);
                    if ($relationship) {
                        $this->eagerLoad[] = $relationship;
                    }
                }
            }
        }

        // Look for subqueries that might indicate eager loading
        if (preg_match_all('/SELECT\s+.*\s+FROM\s+[`"]?(\w+)[`"]?\s+.*\s+WHERE\s+.*\s+IN\s+\(/i', $sql, $matches)) {
            foreach ($matches[1] as $table) {
                $relationship = $this->tableToRelationshipName($table);
                if ($relationship && !in_array($relationship, $this->eagerLoad)) {
                    $this->eagerLoad[] = $relationship;
                }
            }
        }
    }

    /**
     * Convert a table name to a relationship name
     *
     * This is a simple heuristic that converts plural table names to singular relationship names.
     *
     * @param string $table The table name
     * @return string|null The relationship name or null if conversion failed
     */
    protected function tableToRelationshipName(string $table): ?string
    {
        // Simple pluralization rules (not comprehensive)
        $singular = preg_replace(['/s$/', '/ies$/', '/es$/'], ['', 'y', ''], $table);

        // If singular is the same as table, it might not be a relationship
        if ($singular === $table) {
            return null;
        }

        return $singular;
    }

    /**
     * Detect soft deletes from the SQL query
     *
     * This method analyzes the SQL query to detect soft delete patterns
     * and sets the withTrashed and onlyTrashed properties accordingly.
     */
    protected function detectSoftDeletes(): void
    {
        $sql = $this->getSql();

        if (!$sql) {
            return;
        }

        // Check for deleted_at IS NULL condition (normal query without trashed)
        if (preg_match('/WHERE.*deleted_at\s+IS\s+NULL/i', $sql)) {
            // This is a normal query without trashed records
            $this->withTrashed = false;
            $this->onlyTrashed = false;
            return;
        }

        // Check for deleted_at IS NOT NULL condition (only trashed)
        if (preg_match('/WHERE.*deleted_at\s+IS\s+NOT\s+NULL/i', $sql)) {
            $this->withTrashed = true;
            $this->onlyTrashed = true;
            return;
        }

        // If no deleted_at condition is found, it might be a withTrashed query
        if (!preg_match('/deleted_at/i', $sql) && $this->modelClass) {
            // Try to determine if the model uses soft deletes
            try {
                $reflection = new \ReflectionClass($this->modelClass);
                $traits = $reflection->getTraitNames();
                if (in_array('Illuminate\\Database\\Eloquent\\SoftDeletes', $traits)) {
                    $this->withTrashed = true;
                    $this->onlyTrashed = false;
                }
            } catch (\ReflectionException $e) {
                // Ignore reflection errors
            }
        }
    }

    /**
     * Get the Eloquent model or builder class
     *
     * @return string|null The model class
     */
    public function getModelClass(): ?string
    {
        return $this->modelClass;
    }

    /**
     * Get the method being called
     *
     * @return string|null The method name
     */
    public function getMethodName(): ?string
    {
        return $this->methodName;
    }

    /**
     * Check if this is a relation query
     *
     * @return bool True if this is a relation query
     */
    public function isRelation(): bool
    {
        return $this->isRelation;
    }

    /**
     * Get the name of the relation
     *
     * @return string|null The relation name
     */
    public function getRelationName(): ?string
    {
        return $this->relationName;
    }

    /**
     * Get the related model class
     *
     * @return string|null The related model class
     */
    public function getRelatedModel(): ?string
    {
        return $this->relatedModel;
    }

    /**
     * Get the group key
     *
     * @return string|null The group key
     */
    public function getGroupKey(): ?string
    {
        return $this->groupKey;
    }

    /**
     * Check if this is a pivot model
     *
     * @return bool True if this is a pivot model
     */
    public function isPivot(): bool
    {
        return $this->isPivot;
    }

    /**
     * Get the pivot table name
     *
     * @return string|null The pivot table name
     */
    public function getPivotTable(): ?string
    {
        return $this->pivotTable;
    }

    /**
     * Get the query bindings
     *
     * @return array|null The query bindings
     */
    public function getBindings(): ?array
    {
        return $this->bindings;
    }

    /**
     * Set the query bindings
     *
     * @param array|null $bindings The query bindings
     * @return self
     */
    public function setBindings(?array $bindings): self
    {
        $this->bindings = $bindings;
        return $this;
    }

    /**
     * Get the database connection name
     *
     * @return string|null The database connection name
     */
    public function getConnectionName(): ?string
    {
        return $this->connectionName;
    }

    /**
     * Set the database connection name
     *
     * @param string|null $connectionName The database connection name
     * @return self
     */
    public function setConnectionName(?string $connectionName): self
    {
        $this->connectionName = $connectionName;
        return $this;
    }

    /**
     * Get the eager loaded relationships
     *
     * @return array The eager loaded relationships
     */
    public function getEagerLoad(): array
    {
        return $this->eagerLoad;
    }

    /**
     * Set the eager loaded relationships
     *
     * @param array $eagerLoad The eager loaded relationships
     * @return self
     */
    public function setEagerLoad(array $eagerLoad): self
    {
        $this->eagerLoad = $eagerLoad;
        return $this;
    }

    /**
     * Get the applied query scopes
     *
     * @return array The applied query scopes
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /**
     * Set the applied query scopes
     *
     * @param array $scopes The applied query scopes
     * @return self
     */
    public function setScopes(array $scopes): self
    {
        $this->scopes = $scopes;
        return $this;
    }

    /**
     * Check if soft deletes are being used
     *
     * @return bool True if soft deletes are being used
     */
    public function isWithTrashed(): bool
    {
        return $this->withTrashed;
    }

    /**
     * Set whether soft deletes are being used
     *
     * @param bool $withTrashed True if soft deletes are being used
     * @return self
     */
    public function setWithTrashed(bool $withTrashed): self
    {
        $this->withTrashed = $withTrashed;
        return $this;
    }

    /**
     * Check if only trashed models are being queried
     *
     * @return bool True if only trashed models are being queried
     */
    public function isOnlyTrashed(): bool
    {
        return $this->onlyTrashed;
    }

    /**
     * Set whether only trashed models are being queried
     *
     * @param bool $onlyTrashed True if only trashed models are being queried
     * @return self
     */
    public function setOnlyTrashed(bool $onlyTrashed): self
    {
        $this->onlyTrashed = $onlyTrashed;
        return $this;
    }

    /**
     * Get the name of the query span
     *
     * This method returns a concise, human-readable description of the Eloquent query,
     * including the model name, method, relation information, and pivot information if available.
     *
     * @return string The query name
     */
    public function getName(): string
    {
        if (!$this->modelClass) {
            return parent::getName();
        }

        $modelName = $this->getModelName();

        // For pivot models, use a special format
        if ($this->isPivot) {
            $spanName = "Eloquent: Pivot[{$modelName}]";

            // Add pivot table name if available
            if ($this->pivotTable) {
                $spanName .= " ({$this->pivotTable})";
            }
        } else {
            $spanName = "Eloquent: {$modelName}";
        }

        // Add relation info to span name if available
        if ($this->isRelation && $this->relationName) {
            $spanName .= "->{$this->relationName}";
            if ($this->relatedModel) {
                $relatedModelName = $this->getRelatedModelName();
                $spanName .= " ({$relatedModelName})";
            }
        } else if ($this->methodName) {
            $spanName .= "::{$this->methodName}";
        }

        // Add eager loading info if available
        if (!empty($this->eagerLoad)) {
            $eagerLoadStr = implode(', ', array_slice($this->eagerLoad, 0, 3));
            if (count($this->eagerLoad) > 3) {
                $eagerLoadStr .= ", ...";
            }
            $spanName .= " with({$eagerLoadStr})";
        }

        // Add soft delete info if applicable
        if ($this->onlyTrashed) {
            $spanName .= " onlyTrashed";
        } else if ($this->withTrashed) {
            $spanName .= " withTrashed";
        }

        // Add SQL operation and tables
        $sqlInfo = parent::getName();
        if ($sqlInfo !== 'QUERY') {
            $spanName .= " [{$sqlInfo}]";
        }

        return $spanName;
    }

    /**
     * Get the model name (without namespace)
     *
     * @return string|null The model name
     */
    public function getModelName(): ?string
    {
        if (!$this->modelClass) {
            return null;
        }

        return substr($this->modelClass, strrpos($this->modelClass, '\\') + 1);
    }

    /**
     * Get the related model name (without namespace)
     *
     * @return string|null The related model name
     */
    public function getRelatedModelName(): ?string
    {
        if (!$this->relatedModel) {
            return null;
        }

        return substr($this->relatedModel, strrpos($this->relatedModel, '\\') + 1);
    }
}
