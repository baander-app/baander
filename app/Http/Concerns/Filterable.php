<?php

namespace App\Http\Concerns;

use App\Extensions\Eloquent\BaseBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait Filterable
{
    /**
     * Apply global filter to the query.
     *
     * @param BaseBuilder|Builder $query
     * @param string $globalFilter
     * @param array $columns
     * @return void
     */
    protected function applyGlobalFilter(BaseBuilder|Builder $query, string $globalFilter, array $columns): void
    {
        $globalFilter = strtolower($globalFilter);

        $query->where(function ($q) use ($globalFilter, $columns) {
            foreach ($columns as $column) {
                $q->orWhereRaw("$column ILIKE ?", ["%$globalFilter%"]);
            }
        });
    }

    /**
     * Apply column filters to the query.
     *
     * @param BaseBuilder|Builder $query
     * @param array $filters
     * @param array $filterModes
     * @return void
     */
    protected function applyColumnFilters(BaseBuilder|Builder $query, array $filters, array $filterModes): void
    {
        foreach ($filters as $filter) {
            $columnId = $filter['id'];
            $filterValue = strtolower($filter['value']);
            $filterMode = $filterModes[$columnId] ?? 'contains';

            $query->where(function ($q) use ($columnId, $filterValue, $filterMode) {
                if ($filterMode === 'contains') {
                    $q->whereRaw("$columnId ILIKE ?", ["%$filterValue%"]);
                } else if ($filterMode === 'startsWith') {
                    $q->whereRaw("$columnId ILIKE ?", ["$filterValue%"]);
                } else if ($filterMode === 'endsWith') {
                    $q->whereRaw("$columnId ILIKE ?", ["%$filterValue"]);
                }
            });
        }
    }

    /**
     * Apply sorting to the query.
     *
     * @param BaseBuilder|Builder $query
     * @param array $sorting
     * @return void
     */
    protected function applySorting(BaseBuilder|Builder $query, array $sorting): void
    {
        $sort = $sorting[0];
        $columnId = $sort['id'];
        $direction = $sort['desc'] ? 'desc' : 'asc';

        $query->orderBy($columnId, $direction);
    }

    /**
     * Apply the query filters, sorting, and pagination.
     *
     * @param Request $request
     * @param \class-string $model
     * @param array $columnsForGlobalFilter
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    protected function applyFilters(Request $request, string $model, array $columnsForGlobalFilter = [])
    {
        $query = $model::query();

        // Handle global filter
        if ($request->filled('globalFilter')) {
            $this->applyGlobalFilter($query, $request->input('globalFilter'), $columnsForGlobalFilter);
        }

        // Handle column filters
        $filters = json_decode($request->input('filters', '[]'), true);
        $filterModes = json_decode($request->input('filterModes', '[]'), true);
        if ($filters) {
            $this->applyColumnFilters($query, $filters, $filterModes);
        }

        // Handle sorting
        $sorting = json_decode($request->input('sorting', '[]'), true);
        if ($sorting) {
            $this->applySorting($query, $sorting);
        }

        return $query->paginate();
    }
}