<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use JsonSerializable;
use RuntimeException;
use SensitiveParameter;
use stdClass;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Throwable;

/**
 * Host-only input: neither the controller DSN nor this file enters the container.
 * @phpstan-import-type Params from DriverManager
 */
#[Exclude]
final readonly class DeploymentOperatorCredentials implements JsonSerializable
{
    private const int MAX_BYTES = 8192;

    /** @phpstan-param Params $parameters */
    private function __construct(
        #[SensitiveParameter] private array $parameters,
        #[SensitiveParameter] public DeploymentRuntimeEnvironment $runtimeEnvironment,
    ) {}

    public static function fromFile(#[SensitiveParameter] string $absolutePath): self
    {
        $contents = self::readFile($absolutePath);
        try {
            $document = json_decode($contents, false, 6, JSON_THROW_ON_ERROR);
            if (!$document instanceof stdClass
                || array_diff(array_keys(get_object_vars($document)), ['controllerDatabaseUrl', 'runtimeEnvironment']) !== []
                || !isset($document->controllerDatabaseUrl, $document->runtimeEnvironment)
                || !is_string($document->controllerDatabaseUrl)
                || !$document->runtimeEnvironment instanceof stdClass
            ) {
                throw new RuntimeException();
            }
            $variables = get_object_vars($document->runtimeEnvironment);
            foreach (['APP_ENV', 'APP_DEBUG', 'DATABASE_URL', 'REDIS_URL', 'MESSENGER_TRANSPORT_DSN', 'APP_SECRET'] as $key) {
                if (!isset($variables[$key]) || !is_string($variables[$key]) || $variables[$key] === '') {
                    throw new RuntimeException();
                }
            }
            $environment = new DeploymentRuntimeEnvironment($variables);
            $parameters = self::parseDatabaseUrl($document->controllerDatabaseUrl);

            return new self($parameters, $environment);
        } catch (Throwable) {
            // No previous exception: parser traces may contain secret arguments.
            throw new RuntimeException('Deployment credentials contain invalid configuration.');
        }
    }

    /** @phpstan-return Params */
    public function connectionParameters(): array
    {
        return $this->parameters;
    }

    /** @return array{controllerDatabaseUrl: string, runtimeEnvironment: DeploymentRuntimeEnvironment} */
    public function __debugInfo(): array
    {
        return ['controllerDatabaseUrl' => '[redacted]', 'runtimeEnvironment' => $this->runtimeEnvironment];
    }

    /** @return array{controllerDatabaseUrl: string, runtimeEnvironment: DeploymentRuntimeEnvironment} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    private static function readFile(#[SensitiveParameter] string $path): string
    {
        if (!str_starts_with($path, '/') || str_contains($path, "\0") || !function_exists('posix_geteuid')) {
            throw new RuntimeException('Deployment credentials require a private absolute regular file.');
        }
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before === false || @realpath($path) !== $path || !self::isPrivateFile($before)) {
            throw new RuntimeException('Deployment credentials require a private absolute regular file.');
        }
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw new RuntimeException('Cannot read deployment credentials.');
        }
        try {
            $opened = fstat($file);
            clearstatcache(true, $path);
            $current = @lstat($path);
            if ($opened === false || $current === false || !self::isPrivateFile($opened) || !self::isPrivateFile($current)
                || !self::sameFile($before, $opened) || !self::sameFile($opened, $current)
                || @realpath($path) !== $path
            ) {
                throw new RuntimeException('Deployment credentials file changed while opening.');
            }
            $contents = @stream_get_contents($file, self::MAX_BYTES + 1);
            $after = fstat($file);
            clearstatcache(true, $path);
            $current = @lstat($path);
            if ($contents === false || strlen($contents) > self::MAX_BYTES || $after === false || $current === false
                || !self::isPrivateFile($after) || !self::isPrivateFile($current)
                || !self::sameFile($opened, $after) || !self::sameFile($after, $current)
                || $opened['size'] !== $after['size'] || strlen($contents) !== $after['size']
                || @realpath($path) !== $path
            ) {
                throw new RuntimeException('Cannot read a bounded, stable deployment credentials file.');
            }

            return $contents;
        } finally {
            fclose($file);
        }
    }

    /** @param array<int|string,int> $stat Native stat includes both numeric and named keys. */
    private static function isPrivateFile(array $stat): bool
    {
        return ($stat['mode'] & 0170000) === 0100000 && ($stat['mode'] & 0077) === 0
            && $stat['uid'] === posix_geteuid() && $stat['size'] <= self::MAX_BYTES;
    }

    /**
     * @param array<int|string,int> $left
     * @param array<int|string,int> $right
     */
    private static function sameFile(array $left, array $right): bool
    {
        return $left['dev'] === $right['dev'] && $left['ino'] === $right['ino'];
    }

    /** @phpstan-return Params */
    private static function parseDatabaseUrl(#[SensitiveParameter] string $url): array
    {
        if (strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f]/', $url) !== 0
            || !in_array(parse_url($url, PHP_URL_SCHEME), ['postgres', 'postgresql', 'pdo-pgsql', 'pgsql'], true)
        ) {
            throw new RuntimeException();
        }
        $parameters = new DsnParser(['postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql'])->parse($url);
        $allowed = ['driver', 'host', 'port', 'dbname', 'user', 'password', 'charset', 'serverVersion',
            'sslmode', 'sslrootcert', 'sslcert', 'sslkey', 'sslcrl', 'application_name', 'gssencmode'];
        if (($parameters['driver'] ?? null) !== 'pdo_pgsql'
            || array_diff(array_keys($parameters), $allowed) !== []
            || !isset($parameters['host'], $parameters['dbname']) || $parameters['host'] === '' || $parameters['dbname'] === ''
        ) {
            throw new RuntimeException();
        }
        foreach ($parameters as $key => $value) {
            if ((!is_string($value) && !is_int($value))
                || ($key !== 'password' && preg_match('/[;\x00-\x1f\x7f]/', (string) $value) !== 0)
            ) {
                throw new RuntimeException();
            }
        }
        if (isset($parameters['port']) && (!ctype_digit((string) $parameters['port'])
            || (int) $parameters['port'] < 1 || (int) $parameters['port'] > 65535)) {
            throw new RuntimeException();
        }

        return $parameters;
    }
}
