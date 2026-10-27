<?php

declare(strict_types=1);

namespace Baander\RegistryTests;

final class Enrollment
{
    /**
     * @param array{publicId:string,apiKey:string} $registration
     */
    public static function issueToken(string $key, array $registration, int $expiryMs): string
    {
        $message = "baander-registry-enrollment-v1\n" . $registration['publicId'] . "\n"
            . hash('sha256', $registration['apiKey']) . "\n" . $expiryMs;
        return 'v1.' . $expiryMs . '.' . hash_hmac('sha256', $message, $key);
    }
}
