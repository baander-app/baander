<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use Baander\Core\EnvironmentVariable;

/**
 * The secrets a production process refuses to start without. Each must be set, private
 * and at least 32 bytes long: not a value committed to the repository's environment
 * files, not a deployment-example placeholder, and for the TOTP key, 32 decoded bytes.
 *
 * The service credentials are checked too: the Redis password and the database password
 * must be set and not the committed development password, and a Web Push private key, when
 * one is configured, must not be a key the repository ever committed. Problems name the
 * variable and never print its value.
 */
final readonly class ProductionSecrets
{
    public const array VARIABLES = [EnvironmentVariable::APP_SECRET, EnvironmentVariable::STREAM_URL_HMAC_SECRET, EnvironmentVariable::AUTH_TOTP_ENCRYPTION_KEY];
    public const array CREDENTIAL_VARIABLES = [
        EnvironmentVariable::REDIS_PASSWORD,
        EnvironmentVariable::DATABASE_URL,
        EnvironmentVariable::DATABASE_CONTROL_URL,
        EnvironmentVariable::VAPID_PRIVATE_KEY,
    ];
    public const string PLACEHOLDER_PREFIX = 'replace-with-';
    private const int MINIMUM_BYTES = 32;
    private const string BASE64_ENCODED = EnvironmentVariable::AUTH_TOTP_ENCRYPTION_KEY;

    /** The Redis and database password the repository's environment files commit. */
    private const array COMMITTED_PASSWORDS = ['baander'];

    /**
     * SHA-256 digests of the Web Push private keys the repository committed. The keys stay
     * public in history, so a deployment that copied one would sign pushes anyone can forge.
     */
    private const array COMMITTED_VAPID_KEY_DIGESTS = ['7b6c15f1d701c5f65583bbe323791643a5a38e9caf1382b0d005e44c3e3432b3'];

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
        foreach (self::CREDENTIAL_VARIABLES as $variable) {
            $problem = self::credentialProblem($variable, $lookup($variable) ?? '');
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

    private static function credentialProblem(string $variable, string $value): ?string
    {
        $unset = trim($value) === '';

        return match ($variable) {
            EnvironmentVariable::REDIS_PASSWORD => match (true) {
                $unset => $variable . ' is not set.',
                in_array($value, self::COMMITTED_PASSWORDS, true) => $variable . ' is a committed default.',
                default => null,
            },
            EnvironmentVariable::DATABASE_URL => $unset ? $variable . ' is not set.' : self::databasePasswordProblem($variable, $value),
            EnvironmentVariable::DATABASE_CONTROL_URL => $unset ? null : self::databasePasswordProblem($variable, $value),
            EnvironmentVariable::VAPID_PRIVATE_KEY => !$unset && in_array(hash('sha256', $value), self::COMMITTED_VAPID_KEY_DIGESTS, true)
                ? $variable . ' is a committed key.'
                : null,
            default => null,
        };
    }

    private static function databasePasswordProblem(string $variable, string $url): ?string
    {
        $password = (string) (parse_url($url, PHP_URL_PASS) ?? '') |> rawurldecode(...);

        return match (true) {
            $password === '' => $variable . ' has no password.',
            in_array($password, self::COMMITTED_PASSWORDS, true) => $variable . ' uses a committed default password.',
            default => null,
        };
    }
}
