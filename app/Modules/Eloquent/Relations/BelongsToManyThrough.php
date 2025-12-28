<?php

namespace App\Modules\Eloquent\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BelongsToManyThrough extends BelongsToMany
{
    /**
     * The intermediate table name.
     */
    protected string $throughTable;

    /**
     * The foreign key on the through table.
     */
    protected string $throughForeignKey = 'id';

    /**
     * The local key on the through table.
     */
    protected string $throughLocalKey = 'id';

    /**
     * Create a new belongs to many through relationship instance.
     */
    public function __construct(
        Builder $query,
        Model $parent,
        string $throughTable,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey,
        null|string $relationName = null
    ) {
        $this->throughTable = $throughTable;

        parent::__construct($query, $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName);
    }

    /**
     * Set the base constraints on the relation query.
     */
    public function addConstraints()
    {
        // Don't call parent::addConstraints() - prevent duplicate joins
        // The parent's join logic will be added later, so we skip it here
    }

    /**
     * Execute the query as a "select" statement.
     */
    public function get($columns = ['*'])
    {
        // Build the query with our custom joins
        $this->performJoin();

        // Build select columns including pivot data
        $selectColumns = [
            $this->related->getTable() . '.*',
            $this->table . '.' . $this->foreignPivotKey . ' as pivot_' . $this->foreignPivotKey,
            $this->table . '.' . $this->relatedPivotKey . ' as pivot_' . $this->relatedPivotKey,
        ];

        // Add custom pivot columns
        foreach ($this->pivotColumns as $column) {
            $selectColumns[] = $this->table . '.' . $column . ' as pivot_' . $column;
        }

        // Add the through table's foreign key for matching results
        $selectColumns[] = $this->throughTable . '.' . $this->throughForeignKey . ' as pivot_' . $this->throughForeignKey;

        $builder = $this->query->select($selectColumns);

        if ($this->parent->exists) {
            $builder->where($this->throughTable . '.' . $this->throughForeignKey, '=', $this->parent->getAttribute($this->parentKey));
        }

        $results = $builder->get();

        // Hydrate pivot models
        $this->hydratePivotRelations($results);

        return $results;
    }

    /**
     * Hydrate the pivot relationship on the models.
     */
    protected function hydratePivotRelations(Collection $results)
    {
        foreach ($results as $result) {
            $pivotAttributes = [];

            // Extract pivot attributes from the model
            foreach ($result->getAttributes() as $key => $value) {
                if (strncmp($key, 'pivot_', 6) === 0) {
                    $pivotAttributes[substr($key, 6)] = $value;
                    unset($result->$key);
                }
            }

            // Create pivot model instance
            $pivot = $this->newPivot()->setRawAttributes($pivotAttributes);
            $pivot->setRelation($this->relatedPivotKey, $result);

            $result->setRelation('pivot', $pivot);
        }
    }

    /**
     * Set the join clause for the relation query.
     * @param null $query
     */
    protected function performJoin($query = null)
    {
        // Join artists to artist_song
        $this->query->join($this->table, $this->related->getTable() . '.' . $this->relatedKey, '=', $this->table . '.' . $this->foreignPivotKey);

        // Join artist_song to songs
        $this->query->join($this->throughTable, $this->throughTable . '.' . $this->throughLocalKey, '=', $this->table . '.' . $this->relatedPivotKey);
    }

    /**
     * Add the constraints for a relationship query.
     */
    public function addEagerConstraints(array $models)
    {
        // Get the IDs of all parent models
        $keys = $this->getKeys($models, $this->parentKey);

        // Filter by the through table's foreign key
        // Joins will be added when get() is called
        $this->query->whereIn($this->throughTable . '.' . $this->throughForeignKey, $keys);
    }

    /**
     * Match the eagerly loaded results to their parents.
     */
    public function match(array $models, Collection $results, $relation = null)
    {
        $relation = $relation ?: $this->relationName;

        $dictionary = $this->buildDictionary($results);

        // Once we have the dictionary we can spin through the parent models to
        // link them up with their children using the keyed dictionary to make
        // the matching very convenient and fast.
        foreach ($models as $model) {
            $key = $model->getAttribute($this->parentKey);

            if (isset($dictionary[$key])) {
                $model->setRelation($relation, $this->related->newCollection($dictionary[$key]));
            } else {
                $model->setRelation($relation, $this->related->newCollection());
            }
        }

        return $models;
    }

    /**
     * Build model dictionary keyed by the relation's foreign key.
     */
    protected function buildDictionary(Collection $results)
    {
        $dictionary = [];

        // First, we will create a dictionary of models keyed by the through table's foreign key
        foreach ($results as $result) {
            // Get the through foreign key from the pivot data
            $pivotData = $result->pivot->getAttributes();

            $throughKey = $pivotData[$this->throughForeignKey] ?? null;

            if ($throughKey === null) {
                continue;
            }

            if (!isset($dictionary[$throughKey])) {
                $dictionary[$throughKey] = [];
            }

            $dictionary[$throughKey][] = $result;
        }

        return $dictionary;
    }

    /**
     * Set the through table keys.
     */
    public function setThroughKeys(string $foreignKey, string $localKey): self
    {
        $this->throughForeignKey = $foreignKey;
        $this->throughLocalKey = $localKey;

        return $this;
    }

    /**
     * Get the through table foreign key.
     */
    public function getThroughForeignKey(): string
    {
        return $this->throughForeignKey;
    }

    /**
     * Get the query for existence check.
     * This is used by whereHas, withCount, etc.
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        // Perform the necessary joins
        $this->performJoin($query);

        // Select the related table columns
        $query->select($columns);

        return $query;
    }
}
