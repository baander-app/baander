<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use App\Shared\Infrastructure\Redis\RedisClientFactory;
use Defuse\Crypto\Key;
use Doctrine\DBAL\Connection;
use Swoole\Server;
use Throwable;

final class HealthCheckService
{
    private const float MEGABYTE = 1_048_576;

    /**
     * @param array<string, string> $apiKeys External API keys keyed by service name
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly RedisClientFactory $redisClientFactory,
        private readonly string $appEnv,
        private readonly string $appSecret,
        private readonly string $appUrl,
        private readonly string $oauthEncryptionKey,
        private readonly string $oauthPrivateKeyPath,
        private readonly string $oauthPublicKeyPath,
        private readonly string $vapidPublicKey,
        private readonly string $vapidPrivateKey,
        private readonly array $apiKeys = [],
    )
    {
    }

    /**
     * @return HealthCheckResult[]
     */
    public function check(): array
    {
        return [
            $this->checkPostgreSQL(),
            $this->checkRedis(),
            $this->checkSwoole(),
            $this->checkMemory(),
        ];
    }

    /**
     * Dependency readiness — checks if PostgreSQL, Redis, and memory are available.
     *
     * @return HealthCheckResult[]
     */
    public function checkReadiness(): array
    {
        return [
            $this->checkPostgreSQL(),
            $this->checkRedis(),
            $this->checkMemory(),
        ];
    }

    /**
     * Process liveness — checks if the Swoole worker process is alive.
     * Always returns 200 if the process responds, even if Swoole stats are unavailable.
     */
    public function checkLiveness(): HealthCheckResult
    {
        $start = microtime(true);

        if (!function_exists('swoole_get_vm_status')) {
            return new HealthCheckResult(
                component: 'swoole',
                status: HealthStatus::Healthy,
                responseTimeMs: (microtime(true) - $start) * 1000,
                details: ['reason' => 'Process alive (Swoole extension not loaded)'],
            );
        }

        $stats = swoole_get_vm_status();
        if (!is_array($stats) || count($stats) === 0) {
            return new HealthCheckResult(
                component: 'swoole',
                status: HealthStatus::Healthy,
                responseTimeMs: (microtime(true) - $start) * 1000,
                details: ['reason' => 'Process alive (not inside Swoole worker)'],
            );
        }

        return new HealthCheckResult(
            component: 'swoole',
            status: HealthStatus::Healthy,
            responseTimeMs: (microtime(true) - $start) * 1000,
            details: $stats,
        );
    }

    /**
     * Run all configuration validation checks.
     *
     * @return HealthCheckResult[]
     */
    public function checkConfiguration(): array
    {
        return [
            ...$this->checkRequiredEnvVars(),
            ...$this->checkEnvVarFormats(),
            $this->checkAppSecret(),
            $this->checkOAuthKeyFiles(),
            $this->checkOAuthEncryptionKey(),
            ...$this->checkFrameworkConfig(),
            ...$this->checkExternalApiKeys(),
        ];
    }

    public function checkPostgreSQL(): HealthCheckResult
    {
        $start = microtime(true);

        try {
            $result = $this->connection->executeQuery('SELECT 1')->fetchOne();
            $elapsed = (microtime(true) - $start) * 1000;

            return new HealthCheckResult(
                component: 'postgresql',
                status: HealthStatus::Healthy,
                responseTimeMs: $elapsed,
                details: ['connected' => true],
            );
        } catch (Throwable $e) {
            return new HealthCheckResult(
                component: 'postgresql',
                status: HealthStatus::Unhealthy,
                responseTimeMs: (microtime(true) - $start) * 1000,
                details: ['error' => $e->getMessage()],
            );
        }
    }

    public function checkRedis(): HealthCheckResult
    {
        $start = microtime(true);

        try {
            [$pingResult, $dbsize] = $this->redisClientFactory->borrow(function (\Redis $redis): array {
                return [$redis->ping(), $redis->dbsize()];
            });

            $ping = $pingResult === true || $pingResult === 'PONG';
            return new HealthCheckResult(
                component: 'redis',
                status: $ping ? HealthStatus::Healthy : HealthStatus::Unhealthy,
                responseTimeMs: (microtime(true) - $start) * 1000,
                details: [
                    'connected' => true,
                    'dbSize'    => $dbsize,
                ],
            );
        } catch (Throwable $e) {
            return new HealthCheckResult(
                component: 'redis',
                status: HealthStatus::Unhealthy,
                responseTimeMs: (microtime(true) - $start) * 1000,
                details: ['error' => $e->getMessage()],
            );
        }
    }

    private function checkSwoole(): HealthCheckResult
    {
        $start = microtime(true);

        if (!class_exists(Server::class) || !function_exists('swoole_get_vm_status')) {
            return new HealthCheckResult(
                component: 'swoole',
                status: HealthStatus::NotAvailable,
                responseTimeMs: 0.0,
                details: ['reason' => 'Swoole extension not loaded'],
            );
        }

        $stats = swoole_get_vm_status();
        if (!is_array($stats) || count($stats) === 0) {
            return new HealthCheckResult(
                component: 'swoole',
                status: HealthStatus::NotAvailable,
                responseTimeMs: (microtime(true) - $start) * 1000,
                details: ['reason' => 'Not running inside a Swoole worker'],
            );
        }

        return new HealthCheckResult(
            component: 'swoole',
            status: HealthStatus::Healthy,
            responseTimeMs: (microtime(true) - $start) * 1000,
            details: $stats,
        );
    }

    private function checkMemory(): HealthCheckResult
    {
        $start = microtime(true);
        $mb = self::MEGABYTE;

        return new HealthCheckResult(
            component: 'memory',
            status: HealthStatus::Healthy,
            responseTimeMs: (microtime(true) - $start) * 1000,
            details: [
                'usageMb' => round(memory_get_usage() / $mb, 2),
                'peakMb'  => round(memory_get_peak_usage() / $mb, 2),
                'realMb'  => round(memory_get_usage(true) / $mb, 2),
                'limitMb' => ini_get('memory_limit') !== '-1'
                    ? (int)ini_get('memory_limit')
                    : null,
            ],
        );
    }

    // --- Configuration validation checks ---

    /**
     * @return HealthCheckResult[]
     */
    private function checkRequiredEnvVars(): array
    {
        $results = [];
        $isProd = $this->appEnv === 'prod';

        // Always required
        foreach (['DATABASE_URL', 'REDIS_URL', 'APP_SECRET', 'APP_URL', 'APP_DOMAIN'] as $var) {
            $val = getenv($var);
            if ($val === false || $val === '') {
                $results[] = new HealthCheckResult(
                    component: 'env',
                    status: HealthStatus::Unhealthy,
                    responseTimeMs: 0.0,
                    details: [
                        'severity' => 'error',
                        'var' => $var,
                        'message' => sprintf('Required environment variable %s is missing.', $var),
                        'suggestion' => sprintf('Add %s to your .env file.', $var),
                    ],
                );
            }
        }

        // Production-only
        if ($isProd) {
            foreach (['REDIS_PASSWORD', 'OAUTH_ENCRYPTION_KEY'] as $var) {
                $val = getenv($var);
                if ($val === false || $val === '') {
                    $results[] = new HealthCheckResult(
                        component: 'env',
                        status: HealthStatus::Unhealthy,
                        responseTimeMs: 0.0,
                        details: [
                            'severity' => 'error',
                            'var' => $var,
                            'message' => sprintf('Required production variable %s is missing.', $var),
                            'suggestion' => sprintf('Add %s to your .env file before deploying to production.', $var),
                        ],
                    );
                }
            }
        }

        // Feature-dependent (warn only)
        $featureVars = [
            'MAILER_DSN' => 'email',
            'OAUTH_PRIVATE_KEY_PATH' => 'OAuth',
            'OAUTH_PUBLIC_KEY_PATH' => 'OAuth',
            'MESSENGER_TRANSPORT_DSN' => 'async jobs',
        ];
        foreach ($featureVars as $var => $feature) {
            $val = getenv($var);
            if ($val === false || $val === '') {
                $results[] = new HealthCheckResult(
                    component: 'env',
                    status: HealthStatus::NotAvailable,
                    responseTimeMs: 0.0,
                    details: [
                        'severity' => 'warning',
                        'var' => $var,
                        'message' => sprintf('Optional variable %s is not set. %s features will be unavailable.', $var, $feature),
                        'suggestion' => sprintf('Set %s if you need %s functionality.', $var, $feature),
                    ],
                );
            }
        }

        return $results;
    }

    /**
     * @return HealthCheckResult[]
     */
    private function checkEnvVarFormats(): array
    {
        $results = [];
        $isProd = $this->appEnv === 'prod';

        // DATABASE_URL format
        $dbUrl = getenv('DATABASE_URL');
        if ($dbUrl !== false && $dbUrl !== '') {
            if (!str_starts_with($dbUrl, 'postgresql://') && !str_starts_with($dbUrl, 'pdo-pgsql://')) {
                $results[] = new HealthCheckResult(
                    component: 'env',
                    status: HealthStatus::Unhealthy,
                    responseTimeMs: 0.0,
                    details: [
                        'severity' => 'error',
                        'var' => 'DATABASE_URL',
                        'message' => 'DATABASE_URL must start with postgresql:// or pdo-pgsql://.',
                    ],
                );
            }
        }

        // REDIS_URL format
        $redisUrl = getenv('REDIS_URL');
        if ($redisUrl !== false && $redisUrl !== '') {
            if (!str_starts_with($redisUrl, 'redis://')) {
                $results[] = new HealthCheckResult(
                    component: 'env',
                    status: HealthStatus::Unhealthy,
                    responseTimeMs: 0.0,
                    details: [
                        'severity' => 'error',
                        'var' => 'REDIS_URL',
                        'message' => 'REDIS_URL must start with redis://.',
                    ],
                );
            }
        }

        // APP_URL format
        if ($isProd && $this->appUrl !== '') {
            $parsed = parse_url($this->appUrl);
            if ($parsed === false || ($parsed['scheme'] ?? '') !== 'https') {
                $results[] = new HealthCheckResult(
                    component: 'env',
                    status: HealthStatus::Unhealthy,
                    responseTimeMs: 0.0,
                    details: [
                        'severity' => 'error',
                        'var' => 'APP_URL',
                        'message' => 'APP_URL must be a valid HTTPS URL in production.',
                        'suggestion' => 'Set APP_URL to https://your-domain.com.',
                    ],
                );
            }
        }

        return $results;
    }

    private function checkAppSecret(): HealthCheckResult
    {
        if ($this->appEnv !== 'prod') {
            return new HealthCheckResult(
                component: 'app_secret',
                status: HealthStatus::Healthy,
                responseTimeMs: 0.0,
                details: ['severity' => 'ok'],
            );
        }

        if ($this->appSecret === 'change_me_in_production') {
            return new HealthCheckResult(
                component: 'app_secret',
                status: HealthStatus::Unhealthy,
                responseTimeMs: 0.0,
                details: [
                    'severity' => 'error',
                    'message' => 'APP_SECRET is still set to the default placeholder value.',
                    'suggestion' => 'Generate a secure secret (e.g. php -r \'echo bin2hex(random_bytes(32))\') and set APP_SECRET.',
                ],
            );
        }

        return new HealthCheckResult(
            component: 'app_secret',
            status: HealthStatus::Healthy,
            responseTimeMs: 0.0,
            details: ['severity' => 'ok'],
        );
    }

    private function checkOAuthKeyFiles(): HealthCheckResult
    {
        $isProd = $this->appEnv === 'prod';
        $issues = [];
        $worstStatus = HealthStatus::Healthy;

        $keyPaths = [
            'private' => $this->oauthPrivateKeyPath,
            'public' => $this->oauthPublicKeyPath,
        ];

        foreach ($keyPaths as $type => $path) {
            if ($path === '' || $path === '0') {
                $issues[] = sprintf('OAuth %s key path is not configured.', $type);
                $worstStatus = HealthStatus::NotAvailable;
                continue;
            }

            $resolvedPath = str_replace('%kernel.project_dir%', dirname(__DIR__, 3), $path);
            if (!file_exists($resolvedPath) || !is_readable($resolvedPath)) {
                $severity = $isProd ? 'error' : 'warning';
                $status = $isProd ? HealthStatus::Unhealthy : HealthStatus::NotAvailable;
                $issues[] = sprintf('OAuth %s key file not found at: %s (%s)', $type, $path, $severity);
                $worstStatus = match (true) {
                    $worstStatus === HealthStatus::Unhealthy => HealthStatus::Unhealthy,
                    $status === HealthStatus::Unhealthy => HealthStatus::Unhealthy,
                    default => $status,
                };
            }
        }

        if ($issues === []) {
            return new HealthCheckResult(
                component: 'oauth_keys',
                status: HealthStatus::Healthy,
                responseTimeMs: 0.0,
                details: ['severity' => 'ok'],
            );
        }

        return new HealthCheckResult(
            component: 'oauth_keys',
            status: $worstStatus,
            responseTimeMs: 0.0,
            details: [
                'severity' => $worstStatus === HealthStatus::Unhealthy ? 'error' : 'warning',
                'messages' => $issues,
                'suggestion' => 'Set OAUTH_PRIVATE_KEY_PATH and OAUTH_PUBLIC_KEY_PATH in .env and ensure key files exist.',
            ],
        );
    }

    private function checkOAuthEncryptionKey(): HealthCheckResult
    {
        if ($this->appEnv !== 'prod') {
            return new HealthCheckResult(
                component: 'oauth_encryption_key',
                status: HealthStatus::Healthy,
                responseTimeMs: 0.0,
                details: ['severity' => 'ok'],
            );
        }

        if ($this->oauthEncryptionKey === '') {
            return new HealthCheckResult(
                component: 'oauth_encryption_key',
                status: HealthStatus::Unhealthy,
                responseTimeMs: 0.0,
                details: [
                    'severity' => 'error',
                    'message' => 'OAUTH_ENCRYPTION_KEY (auth.encryption_key) is required in production for OAuth token encryption.',
                    'suggestion' => 'Generate a key with: php -r \'echo Defuse\\Crypto\\Key::create()->saveToAsciiSafeString();\' and set the value in auth.yaml.',
                ],
            );
        }

        try {
            Key::loadFromAsciiSafeString($this->oauthEncryptionKey);
        } catch (Throwable) {
            return new HealthCheckResult(
                component: 'oauth_encryption_key',
                status: HealthStatus::Unhealthy,
                responseTimeMs: 0.0,
                details: [
                    'severity' => 'error',
                    'message' => 'OAUTH_ENCRYPTION_KEY is not a valid defuse/php-encryption ASCII-safe string.',
                    'suggestion' => 'Regenerate the key and set the correct value in auth.yaml.',
                ],
            );
        }

        return new HealthCheckResult(
            component: 'oauth_encryption_key',
            status: HealthStatus::Healthy,
            responseTimeMs: 0.0,
            details: ['severity' => 'ok'],
        );
    }

    /**
     * @return HealthCheckResult[]
     */
    private function checkFrameworkConfig(): array
    {
        $results = [];

        // VAPID key pairs (R19)
        $vapidPub = $this->vapidPublicKey;
        $vapidPriv = $this->vapidPrivateKey;
        $hasPub = $vapidPub !== '' && $vapidPub !== '0';
        $hasPriv = $vapidPriv !== '' && $vapidPriv !== '0';

        if ($hasPub !== $hasPriv) {
            $results[] = new HealthCheckResult(
                component: 'vapid_keys',
                status: HealthStatus::NotAvailable,
                responseTimeMs: 0.0,
                details: [
                    'severity' => 'warning',
                    'message' => 'VAPID keys must both be present or both absent. Only one is currently set.',
                    'suggestion' => 'Set both VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY, or remove both.',
                ],
            );
        }

        return $results;
    }

    /**
     * @return HealthCheckResult[]
     */
    private function checkExternalApiKeys(): array
    {
        $results = [];

        $checks = [
            'DISCOGS_TOKEN' => 'Discogs',
            'LASTFM_API_KEY' => 'Last.fm',
            'SPOTIFY_CLIENT_ID' => 'Spotify',
        ];

        foreach ($checks as $envVar => $service) {
            $val = getenv($envVar);
            if ($val === false || $val === '') {
                continue; // R25: empty keys produce no result
            }

            if (strlen(trim($val)) < 8) {
                $results[] = new HealthCheckResult(
                    component: 'api_keys',
                    status: HealthStatus::NotAvailable,
                    responseTimeMs: 0.0,
                    details: [
                        'severity' => 'warning',
                        'var' => $envVar,
                        'message' => sprintf('%s API key value seems too short.', $service),
                        'suggestion' => 'Verify the key is correct.',
                    ],
                );
            }
        }

        return $results;
    }
}
