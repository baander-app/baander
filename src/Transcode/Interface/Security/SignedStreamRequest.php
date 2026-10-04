<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Security;

use App\Transcode\Application\Port\StreamAuthPortInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class SignedStreamRequest
{
    public function __construct(private StreamAuthPortInterface $streamAuth)
    {
    }

    public function validate(Request $request): void
    {
        if (!$this->isValid($request)) {
            throw new AccessDeniedHttpException('Invalid or expired signature.');
        }
    }

    public function isValid(Request $request): bool
    {
        $query = $request->query->all();
        $signature = $query['sig'] ?? null;
        $expiry = $query['exp'] ?? null;
        if (!is_string($signature) || !is_string($expiry) || !ctype_digit($expiry)
            || filter_var($expiry, FILTER_VALIDATE_INT) === false) {
            return false;
        }
        unset($query['sig'], $query['exp']);
        $path = $request->getPathInfo();
        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }

        return $this->streamAuth->validateUrl($path, $signature, (int) $expiry);
    }
}
