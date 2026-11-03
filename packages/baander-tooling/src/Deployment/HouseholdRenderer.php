<?php

declare(strict_types=1);

namespace Baander\Tooling\Deployment;

use Baander\Tooling\Configuration\ApplicationLayout;

final class HouseholdRenderer
{
    public const string POSTGRES_CONFIG_PATH = '/etc/baander/postgresql.conf';

    /** @return array<string,string> */
    public static function environment(HouseholdConfiguration $configuration, ImageProfile $profile): array
    {
        $env = $configuration->environment;
        $redis = 'redis://default:' . rawurlencode($env['REDIS_PASSWORD']) . '@redis:6379';
        return array_replace($env, Pooler::urls($env['DB_USERNAME'], $env['DB_PASSWORD'], $env['DB_DATABASE']), [
            'APP_ENV' => 'prod', 'APP_DEBUG' => '0', 'APP_URL' => $configuration->origin,
            'DEFAULT_URI' => $configuration->origin, 'APP_DOMAIN' => (string) parse_url($configuration->origin, PHP_URL_HOST),
            'BAANDER_DEPLOYMENT_PROFILE' => $configuration->profile,
            'BAANDER_DEPLOYMENT_PROFILE_JSON' => json_encode($profile->deployment, JSON_THROW_ON_ERROR),
            'REDIS_URL' => $redis, 'MESSENGER_TRANSPORT_DSN' => $redis . '/messages',
            'MAILER_DSN' => $env['MAILER_DSN'] ?? 'null://null',
            'MEDIA_STORAGE_PATH' => '/storage/media', 'CONVERT_STORAGE_PATH' => '/storage/conversions',
        ]);
    }

    /** @return array<string,string> */
    public static function workerEnvironment(HouseholdConfiguration $configuration, ImageProfile $profile): array
    {
        return array_intersect_key(self::environment($configuration, $profile), array_flip([
            'APP_ENV', 'APP_DEBUG', 'APP_SECRET', 'DATABASE_URL', 'DATABASE_CONTROL_URL', 'REDIS_URL',
            'REDIS_PASSWORD', 'MESSENGER_TRANSPORT_DSN', 'MAILER_DSN',
            'CONVERT_STORAGE_PATH', 'MEDIA_STORAGE_PATH', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
            'VAPID_PRIVATE_KEY', 'VAPID_PUBLIC_KEY', 'STREAM_URL_HMAC_SECRET', 'APP_URL', 'DEFAULT_URI',
            'BAANDER_DEPLOYMENT_PROFILE', 'BAANDER_DEPLOYMENT_PROFILE_JSON',
        ]));
    }

    /** @param array<string,array<string,mixed>> $images
     * @return array<string,mixed>
     */
    public static function render(HouseholdConfiguration $configuration, array $images, ApplicationLayout $layout, ImageProfile $profile, string $postgresConfiguration, string $owner): array
    {
        if (($profile->deployment['identifier'] ?? null) !== $configuration->profile) {
            throw new \InvalidArgumentException('Configured and resolved deployment profiles disagree.');
        }
        foreach (HouseholdConfiguration::IMAGE_ROLES as $role) {
            if (preg_match('/^sha256:[a-f0-9]{64}$/D', $images[$role]['id'] ?? '') !== 1) {
                throw new \InvalidArgumentException('Rendering requires an immutable ' . $role . ' image ID.');
            }
        }
        $environment = self::environment($configuration, $profile);
        $labels = ['org.baander.household' => $configuration->name, 'org.baander.household.owner' => $owner, 'org.baander.deployment.profile' => $configuration->profile, 'org.baander.deployment.fingerprint' => $profile->fingerprint()];
        $common = ['restart' => 'unless-stopped', 'labels' => $labels, 'networks' => ['private'], 'security_opt' => ['no-new-privileges:true']];
        $webMounts = self::mediaMounts($configuration, $layout);
        $webMounts = array_map(static fn(array $mount): array => ['type' => 'volume', 'source' => array_search($mount['volume'], $configuration->volumes, true), 'target' => $mount['target'], 'read_only' => $mount['readOnly']], $webMounts);
        $webMounts[] = ['type' => 'volume', 'source' => 'secrets', 'target' => $layout->path('config/secrets'), 'read_only' => true];
        $resources = $configuration->resources;
        $services = [
            'web' => $common + [
                'image' => $images['application']['id'], 'environment' => $environment,
                'entrypoint' => ['php'], 'command' => ['-d', 'memory_limit=' . $profile->phpHeapMiB . 'M', $layout->path('bin/console'), 'app:serve', '--no-interaction'],
                'volumes' => $webMounts, 'init' => true, 'stop_grace_period' => '35s', 'cap_drop' => ['ALL'],
                'mem_limit' => $resources['webMemoryMiB'] . 'm', 'pids_limit' => 128,
                'depends_on' => [Pooler::SERVICE => ['condition' => 'service_healthy'], 'redis' => ['condition' => 'service_healthy']],
            ],
            'postgres' => $common + [
                'image' => $images['postgres']['id'],
                'command' => ['postgres', '-c', 'config_file=' . self::POSTGRES_CONFIG_PATH],
                'volumes' => [['type' => 'volume', 'source' => 'postgres_configuration', 'target' => '/etc/baander', 'read_only' => true], ['type' => 'volume', 'source' => 'postgres', 'target' => '/var/lib/postgresql']],
                'environment' => ['POSTGRES_USER' => $environment['DB_USERNAME'], 'POSTGRES_PASSWORD' => $environment['DB_PASSWORD'], 'POSTGRES_DB' => $environment['DB_DATABASE'], 'PGDATA' => '/var/lib/postgresql/data/baander'],
                // Room for every configured connection plus PostgreSQL's background processes.
                'pids_limit' => $configuration->profile === 'full' ? 128 : 96,
                'healthcheck' => ['test' => ['CMD-SHELL', 'pg_isready -h 127.0.0.1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"'], 'interval' => '5s', 'timeout' => '5s', 'retries' => 24],
            ],
            Pooler::SERVICE => $common + Pooler::service(
                $images['postgres']['id'],
                Pooler::environment($configuration->profile, $environment['DB_USERNAME'], $environment['DB_DATABASE'], 'postgres'),
                'pooler_credentials',
                'postgres',
            ) + ['cap_drop' => ['ALL']],
            'redis' => $common + [
                'image' => $images['redis']['id'], 'environment' => ['REDIS_PASSWORD' => $environment['REDIS_PASSWORD']],
                'command' => ['sh', '-ec', 'exec redis-server --dir /data --appendonly yes --maxmemory ' . $resources['redisMaxmemoryMiB'] . 'mb --maxmemory-policy noeviction --requirepass "$REDIS_PASSWORD"'],
                'volumes' => [['type' => 'volume', 'source' => 'redis', 'target' => '/data']],
                'mem_limit' => $resources['redisMemoryMiB'] . 'm', 'pids_limit' => 32,
                'healthcheck' => ['test' => ['CMD-SHELL', 'REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli ping | grep -q PONG'], 'interval' => '5s', 'timeout' => '5s', 'retries' => 24],
            ],
            'gateway' => $common + [
                'image' => $images['gateway']['id'], 'volumes' => [['type' => 'volume', 'source' => 'gateway_configuration', 'target' => '/etc/nginx/conf.d', 'read_only' => true]],
                'ports' => [['target' => 80, 'published' => (string) $configuration->listenPort, 'host_ip' => $configuration->listenAddress, 'protocol' => 'tcp']],
                'depends_on' => ['web' => ['condition' => 'service_healthy']],
                'mem_limit' => $resources['gatewayMemoryMiB'] . 'm', 'pids_limit' => 32,
            ],
        ];
        $volumes = [];
        foreach ($configuration->volumes as $role => $name) {
            $volumes[$role] = ['name' => $name, 'external' => true];
        }
        foreach (self::configurationFiles($configuration, $postgresConfiguration) as $role => $file) {
            $volumes[$role] = ['name' => $file['volume'], 'external' => true];
        }
        foreach ($services as &$service) {
            if (isset($service['mem_limit'])) {
                $service['memswap_limit'] = $service['mem_limit'];
            }
        }
        unset($service);
        return self::escapeInterpolation(['name' => $configuration->name, 'services' => $services, 'volumes' => $volumes,
            'networks' => ['private' => ['name' => $configuration->name . '-private', 'labels' => $labels]]]);
    }

    /** @return array<string,array{volume:string,name:string,content:string}> */
    public static function configurationFiles(HouseholdConfiguration $configuration, string $postgresConfiguration): array
    {
        $files = [];
        foreach (['postgres_configuration' => ['postgresql.conf', $postgresConfiguration], 'gateway_configuration' => ['default.conf', self::gateway()]] as $role => [$name, $content]) {
            $files[$role] = ['volume' => $configuration->name . '-' . str_replace('_', '-', $role) . '-' . substr(hash('sha256', $content), 0, 16), 'name' => $name, 'content' => $content];
        }
        return $files;
    }

    /** @return list<array{volume:string,target:string,role:string,readOnly:bool}> */
    public static function mediaMounts(HouseholdConfiguration $configuration, ApplicationLayout $layout): array
    {
        $mounts = [];
        foreach (['media' => '/storage/media', 'storage' => $layout->path('storage'), 'conversions' => '/storage/conversions', 'transcode_state' => $layout->path('var/transcode_state')] as $role => $target) {
            $mounts[] = ['volume' => $configuration->volumes[$role], 'target' => $target, 'role' => $role === 'media' ? 'source' : 'derived', 'readOnly' => $role === 'media'];
        }
        return $mounts;
    }

    /** @return array<string,int> */
    public static function reservations(HouseholdConfiguration $configuration, ImageProfile $profile): array
    {
        $limits = $configuration->resources;
        unset($limits['redisMaxmemoryMiB']);
        $limits['controlMemoryMiB'] = $profile->controlMemoryMiB;
        $limits['executionMemoryMiB'] = $profile->executionMemoryMiB;
        $limits['totalMiB'] = array_sum($limits);
        return $limits;
    }

    /** @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    private static function escapeInterpolation(array $document): array
    {
        array_walk_recursive($document, static function (&$value): void {
            if (is_string($value)) {
                $value = str_replace('$', '$$', $value);
            }
        });
        return $document;
    }

    private static function gateway(): string
    {
        return <<<'NGINX'
map $http_upgrade $connection_upgrade {
    default upgrade;
    '' close;
}
log_format household '$remote_addr $request_method $uri $status $body_bytes_sent';
server {
    listen 80;
    server_name _;
    server_tokens off;
    access_log /dev/stdout household;
    client_max_body_size 0;
    location / {
        proxy_pass http://web:9501;
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_buffering off;
        proxy_request_buffering off;
        proxy_read_timeout 3600s;
    }
}
NGINX;
    }
}
