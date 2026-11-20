<?php

declare(strict_types=1);

namespace Baander\DevQualification\Fixtures;

/**
 * Test-only secrets for the disposable production containers the fixture workflows start.
 * A production kernel refuses to boot on the committed `.env` defaults, placeholders or
 * values under 32 bytes, so every fixture that runs `APP_ENV=prod` supplies these.
 */
final class FixtureSecrets
{
    public const array ENVIRONMENT = [
        'APP_SECRET' => 'fixture-test-only-app-secret-not-for-production',
        'STREAM_URL_HMAC_SECRET' => 'fixture-test-only-stream-secret-not-for-production',
        'AUTH_TOTP_ENCRYPTION_KEY' => 'Zml4dHVyZS10ZXN0LW9ubHktdG90cC1rZXktMzJiISE=',
    ];

    /** @return list<string> `KEY=VALUE` assignments. */
    public static function assignments(): array
    {
        return array_map(static fn(string $key, string $value): string => $key . '=' . $value, array_keys(self::ENVIRONMENT), self::ENVIRONMENT);
    }

    /** @return list<string> Docker `-e KEY=VALUE` arguments. */
    public static function dockerFlags(): array
    {
        return array_merge(...array_map(static fn(string $assignment): array => ['-e', $assignment], self::assignments()));
    }
}
