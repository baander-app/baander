<?php

declare(strict_types=1);

namespace Baander\Deployment\Presets;

use Baander\Core\EnvironmentVariable;
use Baander\Configuration\ApplicationLayout;
use Baander\Deployment\Profiles\ImageProfile;
use Baander\Deployment\Database\Pooler;
use Baander\Deployment\Observability\LogCollection;
use Baander\Deployment\Workers\KillOrder;

final class Renderer
{
    public const string POSTGRES_CONFIG_PATH = '/etc/baander/postgresql.conf';

    /** OpenTelemetry export stays off unless the configuration's environment sets a collector. */
    public const array ENVIRONMENT_DEFAULTS = [EnvironmentVariable::OTEL_ENABLED => 'false'];

    /** @return array<string,string> */
    public static function environment(Configuration $configuration, ImageProfile $profile): array
    {
        $env = $configuration->environment;
        $redis = 'redis://default:' . rawurlencode($env[EnvironmentVariable::REDIS_PASSWORD]) . '@redis:6379';
        return array_replace(self::ENVIRONMENT_DEFAULTS, $env, Pooler::urls($env['DB_USERNAME'], $env['DB_PASSWORD'], $env['DB_DATABASE']), [
            EnvironmentVariable::APP_ENV => 'prod', EnvironmentVariable::APP_DEBUG => '0', EnvironmentVariable::APP_URL => $configuration->origin,
            EnvironmentVariable::DEFAULT_URI => $configuration->origin, EnvironmentVariable::APP_DOMAIN => (string) parse_url($configuration->origin, PHP_URL_HOST),
            EnvironmentVariable::BAANDER_DEPLOYMENT_PROFILE => json_encode($profile->deployment, JSON_THROW_ON_ERROR),
            EnvironmentVariable::REDIS_URL => $redis, EnvironmentVariable::MESSENGER_TRANSPORT_DSN => $redis . '/messages',
            EnvironmentVariable::MAILER_DSN => $env[EnvironmentVariable::MAILER_DSN] ?? 'null://null',
            EnvironmentVariable::MEDIA_STORAGE_PATH => '/storage/media', EnvironmentVariable::CONVERT_STORAGE_PATH => '/storage/conversions',
            // Web takes forwarding headers from the gateway alone, which sets every one of them itself.
            EnvironmentVariable::TRUSTED_PROXIES => implode(',', $configuration->edge->gatewayAddresses()),
        ]);
    }

    /** @return array<string,string> */
    public static function workerEnvironment(Configuration $configuration, ImageProfile $profile): array
    {
        return array_intersect_key(self::environment($configuration, $profile), array_flip([
            EnvironmentVariable::APP_ENV, EnvironmentVariable::APP_DEBUG, EnvironmentVariable::APP_SECRET, EnvironmentVariable::DATABASE_URL, EnvironmentVariable::DATABASE_CONTROL_URL, EnvironmentVariable::REDIS_URL,
            EnvironmentVariable::REDIS_PASSWORD, EnvironmentVariable::MESSENGER_TRANSPORT_DSN, EnvironmentVariable::MAILER_DSN,
            EnvironmentVariable::CONVERT_STORAGE_PATH, EnvironmentVariable::MEDIA_STORAGE_PATH, EnvironmentVariable::MAIL_FROM_ADDRESS, EnvironmentVariable::VIDEO_ENCODER,
            EnvironmentVariable::VAPID_PRIVATE_KEY, EnvironmentVariable::VAPID_PUBLIC_KEY, EnvironmentVariable::STREAM_URL_HMAC_SECRET, EnvironmentVariable::AUTH_TOTP_ENCRYPTION_KEY, EnvironmentVariable::APP_URL, EnvironmentVariable::DEFAULT_URI,
            EnvironmentVariable::BAANDER_DEPLOYMENT_PROFILE, EnvironmentVariable::OTEL_ENABLED, EnvironmentVariable::OTEL_EXPORTER_OTLP_ENDPOINT,
            EnvironmentVariable::TMDB_BEARER_TOKEN,
        ]));
    }

    /** @param array<string,array<string,mixed>> $images
     * @param array{target:array<string,mixed>,credentials:string,boot:string}|null $logging
     * @return array<string,mixed>
     */
    public static function render(Configuration $configuration, array $images, ApplicationLayout $layout, ImageProfile $profile, string $postgresConfiguration, string $owner, ?array $logging = null): array
    {
        if (($profile->deployment['capability'] ?? null) !== $configuration->capability) {
            throw new \InvalidArgumentException('Configured and resolved deployment capabilities disagree.');
        }
        foreach (Configuration::IMAGE_ROLES as $role) {
            if (preg_match('/^sha256:[a-f0-9]{64}$/D', $images[$role]['id'] ?? '') !== 1) {
                throw new \InvalidArgumentException('Rendering requires an immutable ' . $role . ' image ID.');
            }
        }
        $environment = self::environment($configuration, $profile);
        $environment[EnvironmentVariable::BAANDER_LOG_DIR] = LogCollection::ROOT . '/web/' . ($logging['boot'] ?? $owner);
        if ($logging !== null) {
            $environment[EnvironmentVariable::BAANDER_LOG_NODES] = LogCollection::nodes($configuration->name, $environment[EnvironmentVariable::BAANDER_LOG_NODES] ?? '[]');
        }
        $labels = ['org.baander.preset' => $configuration->name, 'org.baander.preset.owner' => $owner, 'org.baander.deployment.capability' => $configuration->capability, 'org.baander.deployment.fingerprint' => $profile->fingerprint()];
        $common = ['restart' => 'unless-stopped', 'labels' => $labels, 'networks' => ['private'], 'security_opt' => ['no-new-privileges:true']];
        $webMounts = self::mediaMounts($configuration, $layout);
        $webMounts = array_map(static fn(array $mount): array => ['type' => 'volume', 'source' => array_search($mount['volume'], $configuration->volumes, true), 'target' => $mount['target'], 'read_only' => $mount['readOnly']], $webMounts);
        $webMounts[] = ['type' => 'volume', 'source' => 'secrets', 'target' => $layout->path('config/secrets'), 'read_only' => true];
        $webMounts[] = ['type' => 'volume', 'source' => 'logs', 'target' => LogCollection::ROOT];
        if ($logging !== null) {
            $webMounts[] = ['type' => 'bind', 'source' => $logging['credentials'] . '/query-token', 'target' => LogCollection::QUERY_TOKEN, 'read_only' => true, 'bind' => ['create_host_path' => false]];
        }
        $services = [
            'web' => $common + [
                'image' => $images['application']['id'], 'environment' => $environment,
                'entrypoint' => ['php'], 'command' => ['-d', 'memory_limit=' . $profile->phpHeapMiB . 'M', $layout->path('bin/console'), 'app:serve', ...self::webListener($configuration), '--no-interaction'],
                'volumes' => $webMounts, 'init' => true, 'stop_grace_period' => '35s', 'cap_drop' => ['ALL'],
                'oom_score_adj' => KillOrder::WEB,
                'depends_on' => [Pooler::SERVICE => ['condition' => 'service_healthy'], 'redis' => ['condition' => 'service_healthy']],
            ],
            'postgres' => $common + [
                'image' => $images['postgres']['id'],
                'command' => ['postgres', '-c', 'config_file=' . self::POSTGRES_CONFIG_PATH],
                'volumes' => [['type' => 'volume', 'source' => 'postgres_configuration', 'target' => '/etc/baander', 'read_only' => true], ['type' => 'volume', 'source' => 'postgres', 'target' => '/var/lib/postgresql']],
                'environment' => ['POSTGRES_USER' => $environment['DB_USERNAME'], 'POSTGRES_PASSWORD' => $environment['DB_PASSWORD'], 'POSTGRES_DB' => $environment['DB_DATABASE'], 'PGDATA' => '/var/lib/postgresql/data/baander'],
                'oom_score_adj' => KillOrder::POSTGRES,
                'healthcheck' => ['test' => ['CMD-SHELL', 'pg_isready -h 127.0.0.1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"'], 'interval' => '5s', 'timeout' => '5s', 'retries' => 24],
            ],
            Pooler::SERVICE => $common + Pooler::service(
                $images['postgres']['id'],
                Pooler::environment(Pooler::pools($profile->deployment), $environment['DB_USERNAME'], $environment['DB_DATABASE'], 'postgres'),
                'pooler_credentials',
                'postgres',
            ) + ['cap_drop' => ['ALL'], 'oom_score_adj' => KillOrder::POOLER],
            'redis' => $common + [
                'image' => $images['redis']['id'], 'environment' => [EnvironmentVariable::REDIS_PASSWORD => $environment[EnvironmentVariable::REDIS_PASSWORD]],
                'command' => ['sh', '-ec', 'exec redis-server --dir /data --appendonly yes --maxmemory ' . $configuration->resources['redisMaxmemoryMiB'] . 'mb --maxmemory-policy noeviction --requirepass "$REDIS_PASSWORD"'],
                'volumes' => [['type' => 'volume', 'source' => 'redis', 'target' => '/data']],
                'oom_score_adj' => KillOrder::REDIS,
                'healthcheck' => ['test' => ['CMD-SHELL', 'REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli ping | grep -q PONG'], 'interval' => '5s', 'timeout' => '5s', 'retries' => 24],
            ],
            'gateway' => $common + [
                'image' => $images['gateway']['id'], 'volumes' => [['type' => 'volume', 'source' => 'gateway_configuration', 'target' => '/etc/nginx/conf.d', 'read_only' => true]],
                'ports' => [['target' => 80, 'published' => (string) $configuration->listenPort, 'host_ip' => $configuration->listenAddress, 'protocol' => 'tcp']],
                'depends_on' => ['web' => ['condition' => 'service_healthy']],
                'oom_score_adj' => KillOrder::GATEWAY,
            ],
        ];
        // Web and the gateway alone share the edge network, where the gateway holds the addresses web trusts.
        $services['web']['networks'] = ['private', 'edge'];
        $services['gateway']['networks'] = ['edge' => ['ipv4_address' => $configuration->edge->ipv4->gatewayAddress]
            + ($configuration->edge->ipv6 === null ? [] : ['ipv6_address' => $configuration->edge->ipv6->gatewayAddress])];
        if ($logging !== null) {
            $target = \Baander\Containers\Docker\Target::fromArray($logging['target']);
            $groups = [['labels' => ['org.baander.preset' => $configuration->name, 'org.baander.preset.owner' => $owner]], ['labels' => ['app.baander.worker.namespace' => $configuration->name], 'serviceLabel' => 'app.baander.worker.role']];
            $services['log-agent'] = LogCollection::service($images['operator']['id'], $layout->root, $target, $logging['credentials'], LogCollection::configuration($configuration->name, $target, $groups));
            $services['log-agent']['networks'] = ['private'];
            $services['log-agent']['labels'] += $labels;
            foreach ($services as $name => &$service) {
                if ($name !== 'log-agent') {
                    $service['depends_on']['log-agent'] = ['condition' => 'service_started'];
                }
            }
            unset($service);
        }
        $volumes = ['logs' => ['name' => $configuration->name . '-logs', 'external' => true], 'log_index' => ['name' => $configuration->name . '-log-index', 'external' => true]];
        foreach ($configuration->volumes as $role => $name) {
            $volumes[$role] = ['name' => $name, 'external' => true];
        }
        foreach (self::configurationFiles($configuration, $postgresConfiguration) as $role => $file) {
            $volumes[$role] = ['name' => $file['volume'], 'external' => true];
        }
        $networks = [
            'private' => ['name' => $configuration->name . '-private', 'labels' => $labels],
            'edge' => ['name' => EdgeNetwork::name($configuration->name), 'labels' => $labels]
                + ($configuration->edge->dualStack() ? ['enable_ipv6' => true] : [])
                + ['ipam' => ['config' => array_map(self::edgePool(...), $configuration->edge->subnets())]],
        ];

        return self::escapeInterpolation(['name' => $configuration->name, 'services' => $services, 'volumes' => $volumes, 'networks' => $networks]);
    }

    /** @return array<string,array{volume:string,name:string,content:string}> */
    public static function configurationFiles(Configuration $configuration, string $postgresConfiguration): array
    {
        $files = [];
        foreach (['postgres_configuration' => ['postgresql.conf', $postgresConfiguration], 'gateway_configuration' => ['default.conf', self::gateway($configuration)]] as $role => [$name, $content]) {
            $files[$role] = ['volume' => $configuration->name . '-' . str_replace('_', '-', $role) . '-' . substr(hash('sha256', $content), 0, 16), 'name' => $name, 'content' => $content];
        }
        return $files;
    }

    /** @return list<array{volume:string,target:string,role:string,readOnly:bool}> */
    public static function mediaMounts(Configuration $configuration, ApplicationLayout $layout): array
    {
        $mounts = [];
        foreach (['media' => '/storage/media', 'storage' => $layout->path('storage'), 'conversions' => '/storage/conversions', 'transcode_state' => $layout->path('var/transcode_state')] as $role => $target) {
            $mounts[] = ['volume' => $configuration->volumes[$role], 'target' => $target, 'role' => $role === 'media' ? 'source' : 'derived', 'readOnly' => $role === 'media'];
        }
        return $mounts;
    }

    /**
     * The listen address web's server takes on a dual-stack edge network: an IPv6 socket on ::, which
     * also takes IPv4 peers. An IPv4-only deployment keeps the configured 0.0.0.0, which needs no IPv6
     * support from the host's kernel.
     *
     * @return list<string>
     */
    private static function webListener(Configuration $configuration): array
    {
        return $configuration->edge->dualStack() ? ['--host', '::'] : [];
    }

    /** @return array{subnet:string,gateway:string,ip_range:string} */
    private static function edgePool(EdgeSubnet $subnet): array
    {
        return ['subnet' => $subnet->subnet, 'gateway' => $subnet->bridgeAddress, 'ip_range' => $subnet->dynamicRange];
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

    /**
     * Web trusts the gateway's forwarding headers, so the gateway sets each one itself: the client is the
     * TCP peer unless that peer is a declared front proxy, and the protocol is the gateway's own scheme
     * unless a declared front proxy reported HTTP or HTTPS. On a dual-stack edge network the gateway
     * accepts clients in both families and reaches web in both, through Docker's A and AAAA answers for
     * web; on an IPv4-only one it uses IPv4 alone. Resolving web at run time follows a replaced web
     * container to its new addresses.
     */
    private static function gateway(Configuration $configuration): string
    {
        $proxies = $configuration->gatewayFrontProxies();
        $realIp = $proxies === [] ? '' : implode('', [
            ...array_map(static fn(string $proxy): string => 'set_real_ip_from ' . $proxy . ";\n", $proxies),
            "real_ip_header X-Forwarded-For;\n",
            "real_ip_recursive on;\n",
        ]);
        $template = <<<'NGINX'
map $http_upgrade $connection_upgrade {
    default upgrade;
    '' close;
}
upstream web {
    zone web 64k;
    resolver 127.0.0.11 valid=10s;
    server web:9501 resolve;
}
geo $realip_remote_addr $from_front_proxy {
    default 0;
@FRONT_PROXIES@}
map "$from_front_proxy:$http_x_forwarded_proto" $forwarded_proto {
    default $scheme;
    "~^1:(https?)$" $1;
}
@REAL_IP@log_format preset escape=json '{"host":"$host","method":"$request_method","uri":"$uri","status":$status,"bytes":$body_bytes_sent}';
server {
    listen 80;
@LISTEN6@    server_name _;
    server_tokens off;
    access_log /dev/stdout preset;
    client_max_body_size 0;
    location = /api/ws {
        error_log /dev/null;
        proxy_pass http://web;
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Host $http_host;
        proxy_set_header X-Forwarded-Proto $forwarded_proto;
        proxy_set_header X-Forwarded-Port "";
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_buffering off;
        proxy_read_timeout 3600s;
    }
    location / {
        proxy_pass http://web;
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Host $http_host;
        proxy_set_header X-Forwarded-Proto $forwarded_proto;
        proxy_set_header X-Forwarded-Port "";
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_buffering off;
        proxy_request_buffering off;
        proxy_read_timeout 3600s;
    }
}
NGINX;

        return strtr($template, [
            '@FRONT_PROXIES@' => implode('', array_map(static fn(string $proxy): string => '    ' . $proxy . " 1;\n", $proxies)),
            '@REAL_IP@' => $realIp,
            '@LISTEN6@' => $configuration->edge->dualStack() ? "    listen [::]:80;\n" : '',
        ]);
    }
}
