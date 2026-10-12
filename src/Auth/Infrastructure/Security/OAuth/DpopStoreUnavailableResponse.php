<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The answer of a request listener while the DPoP nonce or jti store is unavailable (R7).
 *
 * 503 with Retry-After tells the client to wait and retry with the same credentials;
 * nothing about the proof was decided. No nonce is issued, since none could be stored,
 * and no WWW-Authenticate header, since the request was not refused. The body is the
 * shared API error envelope; this layer cannot use the Interface DTO that renders it.
 */
final class DpopStoreUnavailableResponse
{
    public const int RETRY_AFTER_SECONDS = 5;

    public static function create(): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'message' => 'The authorization server is temporarily unavailable; retry after the Retry-After delay.',
                'code' => Response::HTTP_SERVICE_UNAVAILABLE,
            ],
        ], Response::HTTP_SERVICE_UNAVAILABLE, [
            'Retry-After' => (string) self::RETRY_AFTER_SECONDS,
            'Cache-Control' => 'no-store',
        ]);
    }
}
