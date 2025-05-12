<?php

namespace Baander\ScoutRediSearch;

use Baander\RedisStack\RedisStack;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Searchable;

class RediSearchEngine extends Engine
{
    protected RedisStack $redisStack;

    public function __construct(RedisStack $redisStack)
    {
        $this->redisStack = $redisStack;
    }

    /**
     * Add or update documents in the index.
     *
     * @param \Illuminate\Database\Eloquent\Collection<Searchable> $models
     */
    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $model = $models->first();
        if ($model && method_exists($model, 'searchableAs')) {
            $indexName = $model->searchableAs();
        } else {
            throw new \RuntimeException('No searchable model found.');
        }

        foreach ($models as $model) {
            $data = $model->toSearchableArray();

            if (!empty($data)) {
                $this->redisStack->search()->query($indexName)
                    ->addDocument($model->getScoutKey(), $data); // Ensure `addDocument` exists in `SearchQuery`
            }
        }
    }

    /**
     * Remove documents from the index.
     *
     * @param Collection $models
     */
    public function delete($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $indexName = $models->first()->searchableAs();

        foreach ($models as $model) {
            $this->redisStack->search()->query($indexName)
                ->deleteDocument($model->getScoutKey()); // Ensure `deleteDocument` exists in `SearchQuery`
        }
    }

    /**
     * Perform the given search on the engine.
     *
     * @param Builder $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        $indexName = $builder->model->searchableAs();
        $query = $builder->query;

        $searchQuery = $this->redisStack->search()->query($indexName)->query($query);

        // Apply filters
        foreach ($builder->wheres as $field => $value) {
            $searchQuery->where($field, $value, null);
        }

        // Apply limit if specified
        if ($builder->limit) {
            $searchQuery->limit(0, $builder->limit);
        }

        return $searchQuery->execute(true); // Execute as array results (ensure execute works with `SearchResult`)
    }

    /**
     * Paginate the given search on the engine.
     *
     * @param Builder $builder
     * @param int $perPage
     * @param int $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        $offset = ($page - 1) * $perPage;

        $indexName = $builder->model->searchableAs();
        $query = $builder->query;

        $searchQuery = $this->redisStack->search()->query($indexName)->query($query);

        // Apply where filters
        foreach ($builder->wheres as $field => $value) {
            $searchQuery->where($field, $value, null);
        }

        // Apply limit and offset
        $searchQuery->limit($offset, $perPage);

        return $searchQuery->execute(true); // Execute as array results
    }

    /**
     * Pluck and return the IDs of the given results.
     *
     * @param mixed $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results->getDocuments())->pluck('id')->values();
    }

    /**
     * Map search results to Eloquent models.
     *
     * @param Builder $builder
     * @param mixed $results
     * @param mixed $model
     * @return \Illuminate\Support\Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        if (count($results->getDocuments()) === 0) {
            return Collection::make();
        }

        $keys = collect($results->getDocuments())->pluck('id')->all();

        $models = $model
            ->whereIn($model->getScoutKeyName(), $keys)
            ->get()
            ->keyBy($model->getScoutKeyName());

        return collect($keys)->map(function ($key) use ($models) {
            return $models[$key] ?? null;
        })->filter();
    }

    /**
     * Retrieve lazy-mapped results.
     *
     * @param Builder $builder
     * @param mixed $results
     * @param mixed $model
     * @return \Illuminate\Support\LazyCollection
     */
    public function lazyMap(Builder $builder, $results, $model)
    {
        return $this->map($builder, $results, $model)->lazy();
    }

    /**
     * Get the total number of results from the search query.
     *
     * @param mixed $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results->getCount();
    }

    /**
     * Flush all of the model's records from the index.
     *
     * @param mixed $model
     */
    public function flush($model)
    {
        $indexName = $model->searchableAs();

        // Use the IndexManager from RedisStack to drop the index completely
        $this->redisStack->indexes()->drop($indexName);
    }


    /**
     * Create a new index with the given options.
     *
     * @param string $name
     * @param array $options
     */
    public function createIndex($name, array $options = [])
    {
        $this->redisStack->indexes()->create($name, $options); // Ensure `create` exists in `IndexManager`
    }

    /**
     * Delete an index by its name.
     *
     * @param string $name
     */
    public function deleteIndex($name)
    {
        $this->redisStack->indexes()->drop($name); // Ensure `drop` exists in `IndexManager`
    }
}