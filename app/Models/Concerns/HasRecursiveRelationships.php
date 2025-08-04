<?php

namespace App\Models\Concerns;

use App\Modules\Eloquent\RecursiveBaseBuilder;
use Illuminate\Database\Query\Builder;
use Staudenmeir\LaravelAdjacencyList\Eloquent\HasRecursiveRelationships as BaseHasRecursiveRelationships;

trait HasRecursiveRelationships
{
    use BaseHasRecursiveRelationships;

    /**
     * Create a new Eloquent query builder for the model.
     *
     * @param  Builder  $query
     * @return RecursiveBaseBuilder
     */
    public function newEloquentBuilder($query): RecursiveBaseBuilder
    {
        return new RecursiveBaseBuilder($query);
    }
}
