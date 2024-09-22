<?php

namespace App\Http\Integrations\Github\Requests;

use App\Http\Integrations\Github\Dto\Repository;
use Saloon\Enums\Method;
use Saloon\Http\{Request, Response};

class GetBaanderReleases extends Request
{
    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::GET;

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return '/repos/baander-app/baander/releases';
    }


    public function createDtoFromResponse(Response $response): mixed
    {
        abort_if($response->status() !== 200, 'Unexpected HTTP status code ' . $response->status());
        $data = $response->json();

        return Repository::from($data);
    }
}
