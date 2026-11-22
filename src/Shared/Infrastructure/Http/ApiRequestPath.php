<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Whether a request is an API request, read from its percent-decoded path as the router
 * and the firewall read it. `Request::getPathInfo()` keeps the request's own encoding, so
 * `/%61pi/playlists` reaches the `/api/playlists` route; a raw prefix check would treat it
 * as a non-API request.
 */
final class ApiRequestPath
{
    private const string PREFIX = '/api/';

    public static function isApi(Request $request): bool
    {
        $path = $request->getPathInfo() |> rawurldecode(...);

        return str_starts_with($path, self::PREFIX);
    }
}
