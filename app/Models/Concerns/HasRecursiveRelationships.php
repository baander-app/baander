<?php

namespace App\Models\Concerns;

use App\Modules\Eloquent\RecursiveBaseBuilder;
use Staudenmeir\LaravelAdjacencyList\Eloquent\HasRecursiveRelationships as BaseHasRecursiveRelationships;

trait HasRecursiveRelationships
{
    use BaseHasRecursiveRelationships;

    /**
     * Create a new Eloquent query builder for the model.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return \App\Modules\Eloquent\RecursiveBaseBuilder
     */
    public function newEloquentBuilder($query): RecursiveBaseBuilder
    {
        return new RecursiveBaseBuilder($query);
    }
}
