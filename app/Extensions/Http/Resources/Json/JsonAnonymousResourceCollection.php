<?php

namespace App\Extensions\Http\Resources\Json;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class JsonAnonymousResourceCollection extends AnonymousResourceCollection
{
    protected function preparePaginatedResponse($request)
    {
        if ($this->preserveAllQueryParameters) {
            $this->resource->appends($request->query());
        } else if (!is_null($this->queryParameters)) {
            $this->resource->appends($this->queryParameters);
        }

        return (new JsonPaginatedResourceResponse($this))->toResponse($request);
    }
}