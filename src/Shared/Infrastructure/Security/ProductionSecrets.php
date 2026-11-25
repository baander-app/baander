<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use Baander\Core\EnvironmentVariable;
use Baander\Core\Secrets;

/**
 * The secrets a production process refuses to start without, judged by the rules the
 * deployment preset applies too ({@see Secrets}). Each must be set, private and at least 32
 * bytes long: not a value committed to the repository's environment files, not a
 * deployment-example placeholder, and for the TOTP key, 32 decoded bytes.
 *
 * The service credentials are checked too: the Redis password and the database password
 * must be set, at least 16 bytes long and not the committed development password, in
 * REDIS_PASSWORD and in each connection URL that carries one, and a Web Push private key,
 * when one is configured, must not be a key the repository ever committed. The test values
 * `.env.test` commits pass this check, because the tests boot production kernels on them;
 * the preset refuses them. Problems name the variable and never print its value.
 */
final readonly class ProductionSecrets
{
    public const array VARIABLES = [EnvironmentVariable::APP_SECRET, EnvironmentVariable::STREAM_URL_HMAC_SECRET, EnvironmentVariable::AUTH_TOTP_ENCRYPTION_KEY];
    public const array CREDENTIAL_VARIABLES = [
        EnvironmentVariable::REDIS_PASSWORD,
        EnvironmentVariable::REDIS_URL,
        EnvironmentVariable::DATABASE_URL,
        EnvironmentVariable::DATABASE_CONTROL_URL,
        EnvironmentVariable::VAPID_PRIVATE_KEY,
    ];
    public const string PLACEHOLDER_PREFIX = Secrets::PLACEHOLDER_PREFIX;
    private const string BASE64_ENCODED = EnvironmentVariable::AUTH_TOTP_ENCRYPTION_KEY;

    /**
     * SHA-256 digests of the Web Push private keys the repository committed. The keys stay
     * public in history, so a deployment that copied one would sign pushes anyone can forge.
     */
    private const array COMMITTED_VAPID_KEY_DIGESTS = ['7b6c15f1d701c5f65583bbe323791643a5a38e9caf1382b0d005e44c3e3432b3'];

    /**
     * @param callable(string): ?string $lookup Resolves an environment variable, null when unset
     * @return list<string>
     */
    public function problems(callable $lookup): array
    {
        $problems = [];
        foreach (self::VARIABLES as $variable) {
            $problem = Secrets::secretProblem($variable, $lookup($variable) ?? '', $variable === self::BASE64_ENCODED);
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

    private static function credentialProblem(string $variable, string $value): ?string
    {
        $unset = trim($value) === '';

        return match ($variable) {
            EnvironmentVariable::REDIS_PASSWORD => $unset ? $variable . ' is not set.' : Secrets::passwordProblem($variable, $value),
            // The application authenticates with the URL's password; REDIS_PASSWORD may differ.
            EnvironmentVariable::REDIS_URL => $unset ? null : self::urlPasswordProblem($variable, $value),
            EnvironmentVariable::DATABASE_URL => $unset ? $variable . ' is not set.' : self::urlPasswordProblem($variable, $value),
            EnvironmentVariable::DATABASE_CONTROL_URL => $unset ? null : self::urlPasswordProblem($variable, $value),
            EnvironmentVariable::VAPID_PRIVATE_KEY => !$unset && in_array(hash('sha256', $value), self::COMMITTED_VAPID_KEY_DIGESTS, true)
                ? $variable . ' is a committed key.'
                : null,
            default => null,
        };
    }

    private static function urlPasswordProblem(string $variable, string $url): ?string
    {
        $password = self::urlPassword($url);

        return match (true) {
            $password === '' => $variable . ' has no password.',
            in_array($password, Secrets::COMMITTED_PASSWORDS, true) => $variable . ' uses a committed default password.',
            str_starts_with($password, Secrets::PLACEHOLDER_PREFIX) => $variable . ' uses a placeholder password.',
            strlen($password) < Secrets::MINIMUM_PASSWORD_BYTES => sprintf('%s carries a password shorter than %d bytes.', $variable, Secrets::MINIMUM_PASSWORD_BYTES),
            default => null,
        };
    }

    private static function urlPassword(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_PASS) ?? '') |> rawurldecode(...);
    }
}
