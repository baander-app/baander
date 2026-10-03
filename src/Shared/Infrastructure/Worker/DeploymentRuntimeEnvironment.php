<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Closure;
use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Explicit production application configuration, never inherited host environment.
 * The allowlist covers the configured framework, database, cache, Messenger and
 * mailer inputs. Worker identity and process/loader configuration are excluded.
 * Public variables are sensitive; Docker daemon inspectors can read container
 * environment. Debug/JSON redaction does not encrypt that metadata.
 */
#[Exclude]
final readonly class DeploymentRuntimeEnvironment implements JsonSerializable
{
    private const array ALLOWED_KEYS = [
        'APP_ENV', 'APP_DEBUG', 'APP_SECRET', 'DATABASE_URL', 'REDIS_URL',
        'REDIS_PASSWORD', 'MESSENGER_TRANSPORT_DSN', 'MAILER_DSN',
    ];

    /** @var array<string,string> */
    public array $variables;

    /** @param array<array-key,mixed> $variables */
    public function __construct(#[SensitiveParameter] array $variables = [])
    {
        if (count($variables) > 32) {
            throw new InvalidArgumentException('Runtime environment accepts at most 32 entries.');
        }
        $bytes = 0;
        $validated = [];
        foreach ($variables as $key => $value) {
            if (!is_string($key) || preg_match('/\A[A-Z][A-Z0-9_]*\z/D', $key) !== 1
                || str_starts_with($key, 'BAANDER_WORKER_') || !in_array($key, self::ALLOWED_KEYS, true)
            ) {
                throw new InvalidArgumentException('Runtime environment key is not admitted.');
            }
            if (!is_string($value) || strlen($value) > 2048 || preg_match('//u', $value) !== 1
                || preg_match('/[\p{C}\p{Zl}\p{Zp}]/u', $value) !== 0
            ) {
                throw new InvalidArgumentException('Runtime environment values require printable UTF-8 within 2048 bytes.');
            }
            if (($key === 'APP_ENV' && $value !== 'prod') || ($key === 'APP_DEBUG' && $value !== '0')) {
                throw new InvalidArgumentException('Runtime environment requires production mode with debugging disabled.');
            }
            $bytes += strlen($key) + strlen($value) + 2;
            $validated[$key] = $value;
        }
        if ($bytes > 4096) {
            throw new InvalidArgumentException('Runtime environment file exceeds 4096 bytes.');
        }
        ksort($validated, SORT_STRING);
        $this->variables = $validated;
    }

    /**
     * Invoke the operation with a private absolute Docker env-file path. Values
     * retain literal bytes: no quoting, interpolation or shell execution occurs.
     *
     * @template T
     * @param Closure(string): T $operation
     * @return T
     */
    public function withFile(Closure $operation): mixed
    {
        $parent = sys_get_temp_dir();
        if (!str_starts_with($parent, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Runtime environment requires an absolute temporary parent.');
        }
        $directory = rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'baander-runtime-' . bin2hex(random_bytes(16));
        if (!@mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot create private runtime environment directory.');
        }
        $path = $directory . DIRECTORY_SEPARATOR . 'environment';
        try {
            $file = @fopen($path, 'xb');
            if ($file === false) {
                throw new RuntimeException('Cannot create runtime environment file.');
            }
            try {
                if (!@chmod($path, 0600)) {
                    throw new RuntimeException('Cannot secure runtime environment file.');
                }
                $contents = '';
                foreach ($this->variables as $key => $value) {
                    $contents .= $key . '=' . $value . "\n";
                }
                if ($contents !== '' && @fwrite($file, $contents) !== strlen($contents)) {
                    throw new RuntimeException('Cannot write runtime environment file.');
                }
            } finally {
                fclose($file);
            }

            return $operation($path);
        } finally {
            $removedFile = true;
            if (file_exists($path) || is_link($path)) {
                $removedFile = @unlink($path);
            }
            $removedDirectory = @rmdir($directory);
            if (!$removedFile || !$removedDirectory) {
                throw new RuntimeException('Cannot remove private runtime environment artifacts.');
            }
        }
    }

    /** @return array{variables: array<string,string>} */
    public function __debugInfo(): array
    {
        return ['variables' => array_fill_keys(array_keys($this->variables), '[redacted]')];
    }

    /** @return array{variables: array<string,string>} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
