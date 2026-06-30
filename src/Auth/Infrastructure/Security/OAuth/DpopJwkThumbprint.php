<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use InvalidArgumentException;

final class DpopJwkThumbprint
{
    private const CANONICAL_MEMBERS = [
        'RSA' => ['e', 'kty', 'n'],
        'EC' => ['crv', 'kty', 'x', 'y'],
        'OKP' => ['crv', 'kty', 'x'],
    ];

    public static function compute(array $jwk): string
    {
        $kty = $jwk['kty'] ?? null;
        if ($kty === null) {
            throw new InvalidArgumentException('JWK must contain "kty" member.');
        }

        $members = self::CANONICAL_MEMBERS[$kty] ?? null;
        if ($members === null) {
            throw new InvalidArgumentException(sprintf('Unsupported JWK key type: "%s".', $kty));
        }

        foreach ($members as $member) {
            if (!array_key_exists($member, $jwk)) {
                throw new InvalidArgumentException(sprintf('JWK missing required member "%s" for type "%s".', $member, $kty));
            }
        }

        $canonical = new \stdClass();
        foreach ($members as $member) {
            $canonical->$member = $jwk[$member];
        }

        $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new InvalidArgumentException('Failed to encode canonical JWK to JSON.');
        }

        return self::base64urlEncode(hash('sha256', $json, binary: true));
    }

    private static function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
