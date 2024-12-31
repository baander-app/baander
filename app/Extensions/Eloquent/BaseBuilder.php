<?php

namespace App\Extensions\Eloquent;

use App\Extensions\Pagination\JsonPaginator;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;

class BaseBuilder extends Builder
{
    /**
     * Apply relations safely.
     *
     * @param array|null $allowedRelations
     * @param string|null $relations
     * @return $this
     */
    public function withRelations(?array $allowedRelations, ?string $relations)
    {
        if (empty($relations)) {
            return $this;
        }

        $relationsArray = array_filter(
            array_map('trim', explode(',', $relations)),
            fn($relation) => in_array($relation, $allowedRelations),
        );

        if (!empty($relationsArray)) {
            $this->with($relationsArray);
        }

        return $this;
    }

    /**
     * Select fields safely.
     *
     * @param array|null $allowedFields
     * @param string|null $fields
     * @return $this
     */
    public function selectFields(?array $allowedFields, ?string $fields)
    {
        if (empty($fields)) {
            return $this;
        }

        $fieldsArray = array_filter(
            array_map('trim', explode(',', $fields)),
            fn($field) => in_array($field, $allowedFields),
        );

        if (!empty($fieldsArray)) {
            $this->select($fieldsArray);
        }

        return $this;
    }

    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
    {
        if ($page === null) {
            $qs = request()->query('limit');
            if ($qs && filter_var($qs, FILTER_VALIDATE_INT)) {
                $perPage = $qs;
            }
        }

        return parent::paginate($perPage, $columns, $pageName, $page, $total);
    }


    protected function paginator($items, $total, $perPage, $currentPage, $options)
    {
        return Container::getInstance()->makeWith(JsonPaginator::class, compact(
            'items', 'total', 'perPage', 'currentPage', 'options',
        ));
    }
}