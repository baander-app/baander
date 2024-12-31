<?php

namespace App\Http\Integrations\Github\Requests;

use App\Http\Integrations\CachesSaloonRequests;
use App\Http\Integrations\Github\Dto\Repository;
use Saloon\CachePlugin\Contracts\Cacheable;
use Saloon\Enums\Method;
use Saloon\Http\{Request, Response};

class GetRepository extends Request implements Cacheable
{
    use CachesSaloonRequests;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::GET;

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return '/repos/baander-app/baander';
    }

    public function createDtoFromResponse(Response $response): mixed
    {
        abort_if($response->status() !== 200, 'Unexpected HTTP status code ' . $response->status());
        $data = $response->json();

        return Repository::from($data);
    }


    public function cacheExpiryInSeconds(): int
    {
        return 3600;
    }
}
