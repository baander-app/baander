<?php

declare(strict_types=1);

namespace Baander\Core;

/**
 * The rules a deployment's secrets and service passwords meet. The production boot check and
 * the deployment preset both apply them, so the placeholder, committed-value and length rules
 * cannot drift between render and boot. A problem names the variable and never its value.
 *
 * The committed values are listed rather than read from the environment files, so an operator
 * who writes a real secret into a deployed `.env` is not refused for it.
 */
final class Secrets
{
    public const string PLACEHOLDER_PREFIX = 'replace-with-';
    public const int MINIMUM_SECRET_BYTES = 32;
    public const int MINIMUM_PASSWORD_BYTES = 16;

    /** The values `.env.dev` and `.env.example` commit for the application secrets. */
    public const array COMMITTED_DEFAULTS = [
        'change_me_in_production',
        'aba6bf1488f290aaaf6f5a8371bd0f6c',
        'dev-secret-change-me-in-production',
        'change-me-to-a-random-64-char-secret-in-production',
        'vhDAUukeCl95XW2ZyaTcQbB67hk3cv0yLDiezobAarA=',
    ];

    /** The Redis and database password the development environment files commit. */
    public const array COMMITTED_PASSWORDS = ['baander'];

    /**
     * The secrets and passwords `.env.test` and the test stack's `compose.test.yaml` commit.
     * The tests boot production kernels on them, so the boot check accepts them; the preset
     * refuses them.
     */
    public const array COMMITTED_TEST_VALUES = [
        'test-only-app-secret-never-use-in-production',
        'test-only-stream-url-secret-never-use-in-production',
        '5IG63F//ty2qMfGR70X0miwxsdGGVFfIpy61FUgK5Qc=',
        'test-only-database-password',
        'test-only-redis-password',
        'test-only-postgres-superuser-password',
        'test-only-redis-admin-password',
    ];

    /** A problem with an application secret, or null; base64 secrets are measured decoded. */
    public static function secretProblem(string $variable, string $value, bool $base64 = false): ?string
    {
        if (trim($value) === '') {
            return $variable . ' is not set.';
        }
        if (in_array($value, self::COMMITTED_DEFAULTS, true)) {
            return $variable . ' is a committed default.';
        }
        if (str_starts_with($value, self::PLACEHOLDER_PREFIX)) {
            return $variable . ' is a placeholder.';
        }
        $bytes = $base64 ? base64_decode($value, true) : $value;
        if ($bytes === false) {
            return $variable . ' is not base64.';
        }

        return strlen($bytes) < self::MINIMUM_SECRET_BYTES
            ? sprintf('%s is shorter than %d bytes.', $variable, self::MINIMUM_SECRET_BYTES)
            : null;
    }

    /** A problem with a Redis or database password that is set, or null. */
    public static function passwordProblem(string $variable, string $password): ?string
    {
        return match (true) {
            in_array($password, self::COMMITTED_PASSWORDS, true) => $variable . ' is a committed default.',
            str_starts_with($password, self::PLACEHOLDER_PREFIX) => $variable . ' is a placeholder.',
            strlen($password) < self::MINIMUM_PASSWORD_BYTES => sprintf('%s is shorter than %d bytes.', $variable, self::MINIMUM_PASSWORD_BYTES),
            default => null,
        };
    }

    /** Whether a value is one the repository commits anywhere, its test values included. */
    public static function committed(string $value): bool
    {
        return in_array($value, [...self::COMMITTED_DEFAULTS, ...self::COMMITTED_PASSWORDS, ...self::COMMITTED_TEST_VALUES], true);
    }
}
