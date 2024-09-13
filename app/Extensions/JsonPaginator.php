<?php

namespace App\Extensions;

use Illuminate\Pagination\LengthAwarePaginator;

class JsonPaginator extends LengthAwarePaginator
{
    protected array $queryParams = [
        'page'    => 'currentPage',
        'perPage' => 'perPage',
    ];

    public function toArray(): array
    {
        return [
            'data'        => $this->items->toArray(),
            'total'       => $this->total(),
            'count'       => $this->count(),
            'perPage'     => $this->perPage(),
            'currentPage' => $this->currentPage(),
            'lastPage'    => $this->lastPage(),
        ];
    }

    public function appends($key, $value = null): JsonPaginator|static
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->query[$this->queryParams[$k] ?? $k] = $v;
            }
        } else {
            $this->query[$this->queryParams[$key] ?? $key] = $value;
        }

        return $this;
    }

    public function withPath($path): JsonPaginator|static
    {
        $this->path = $path;

        return $this;
    }

    public function currentPage(): int|string
    {
        $pageName = $this->queryParams['page'];
        $defaultPage = $this->pageName;
        $page = LengthAwarePaginator::resolveCurrentPage($pageName);

        return $this->isValidPageNumber($page) ? $page : $defaultPage;
    }

    public function perPage(): int
    {
        $perPageName = $this->queryParams['perPage'];
        $defaultPerPage = $this->perPage;

        return (int)LengthAwarePaginator::resolveCurrentPage($perPageName, $defaultPerPage);
    }
}