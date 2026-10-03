<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Adapter\OAuth;

use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class DpopTokenResponse extends BearerTokenResponse
{
    public function generateHttpResponse(ResponseInterface $response): ResponseInterface
    {
        $response = parent::generateHttpResponse($response);
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('The token response must be a JSON object.');
        }
        $data['token_type'] = 'DPoP';

        // The shorter token type must replace the stream, not leave trailing bytes.
        return $response->withBody(Stream::create(json_encode($data, JSON_THROW_ON_ERROR)));
    }
}
