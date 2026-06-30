<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Adapter\OAuth;

use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;
use Psr\Http\Message\ResponseInterface;

final class DpopTokenResponse extends BearerTokenResponse
{
    public function generateHttpResponse(ResponseInterface $response): ResponseInterface
    {
        $response = parent::generateHttpResponse($response);

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (is_array($data)) {
            $data['token_type'] = 'DPoP';

            $newBody = json_encode($data);
            if ($newBody !== false) {
                $response->getBody()->rewind();
                $response->getBody()->write($newBody);
            }
        }

        return $response;
    }
}
