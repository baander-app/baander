<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use Baander\Core\EnvironmentVariable;

/**
 * The secrets a production process refuses to start without. Each must be set, private
 * and at least 32 bytes long: not a value committed to the repository's environment
 * files, not a deployment-example placeholder, and for the TOTP key, 32 decoded bytes.
 * Problems name the variable and never print its value.
 */
final readonly class ProductionSecrets
{
    public const array VARIABLES = [EnvironmentVariable::APP_SECRET, EnvironmentVariable::STREAM_URL_HMAC_SECRET, EnvironmentVariable::AUTH_TOTP_ENCRYPTION_KEY];
    public const string PLACEHOLDER_PREFIX = 'replace-with-';
    private const int MINIMUM_BYTES = 32;
    private const string BASE64_ENCODED = EnvironmentVariable::AUTH_TOTP_ENCRYPTION_KEY;

    /**
     * The values `.env`, `.env.dev` and `.env.example` commit for these variables. They
     * are listed here rather than read from the files, so an operator who writes a real
     * secret into a deployed `.env` is not refused for it.
     */
    private const array COMMITTED_DEFAULTS = [
        'change_me_in_production',
        'aba6bf1488f290aaaf6f5a8371bd0f6c',
        'dev-secret-change-me-in-production',
        'change-me-to-a-random-64-char-secret-in-production',
        'vhDAUukeCl95XW2ZyaTcQbB67hk3cv0yLDiezobAarA=',
    ];

    /**
     * @param callable(string): ?string $lookup Resolves an environment variable, null when unset
     * @return list<string>
     */
    public function problems(callable $lookup): array
    {
        $problems = [];
        foreach (self::VARIABLES as $variable) {
            $problem = self::problem($variable, $lookup($variable) ?? '');
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * @param callable(string): ?string $lookup
     * @throws ProductionSecretsRefusedException
     */
    public function assertConfigured(callable $lookup): void
    {
        $problems = $this->problems($lookup);
        if ($problems !== []) {
            throw new ProductionSecretsRefusedException($problems);
        }
    }

    /** Reads a variable the way the container's environment resolution does. */
    public static function environmentValue(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) ? $value : null;
    }

    private static function problem(string $variable, string $value): ?string
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
        $bytes = $value;
        if ($variable === self::BASE64_ENCODED) {
            $decoded = base64_decode($value, true);
            if ($decoded === false) {
                return $variable . ' is not base64.';
            }
            $bytes = $decoded;
        }

        return strlen($bytes) < self::MINIMUM_BYTES
            ? sprintf('%s is shorter than %d bytes.', $variable, self::MINIMUM_BYTES)
            : null;
    }
}
