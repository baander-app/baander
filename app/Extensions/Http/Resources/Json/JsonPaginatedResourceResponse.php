<?php

namespace App\Extensions\Http\Resources\Json;

use Illuminate\Http\Resources\Json\PaginatedResourceResponse;

class JsonPaginatedResourceResponse extends PaginatedResourceResponse
{
    protected function paginationInformation($request)
    {
        $paginated = $this->resource->resource->toArray();

        $default = [
            'links' => $this->paginationLinks($paginated),
            ...$this->meta($paginated),
        ];

        if (method_exists($this->resource, 'paginationInformation') ||
            $this->resource->hasMacro('paginationInformation')) {
            return $this->resource->paginationInformation($request, $paginated, $default);
        }

        return $default;
    }
}