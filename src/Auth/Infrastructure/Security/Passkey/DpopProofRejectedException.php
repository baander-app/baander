<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\Passkey;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/** Passkey login stopped before the WebAuthn ceremony because its DPoP proof was missing or invalid. */
final class DpopProofRejectedException extends AuthenticationException
{
    public function __construct(public readonly JsonResponse $response)
    {
        parent::__construct('DPoP proof rejected.');
    }

    public function getMessageKey(): string
    {
        return 'DPoP proof rejected.';
    }
}
