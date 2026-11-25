<?php

declare(strict_types=1);

namespace Baander\Deployment\Observability;

use Baander\Core\EnvironmentVariable;
use Baander\Containers\Docker\Target;
use Baander\Filesystem\AtomicFile;
use Baander\Filesystem\PrivateDirectory;
use Baander\Deployment\Workers\KillOrder;

/**
 * Host-owned collection credentials and an inventory pinned to the captured daemon. The agent
 * reaches Docker through an allow-listing proxy: an nginx from the gateway image that alone
 * mounts the daemon's socket and listens on a Unix socket in a volume it shares with the
 * agent. The proxy joins no network and publishes no port.
 */
final class LogCollection
{
    public const string ROOT = '/var/log/baander';
    public const string QUERY_TOKEN = '/run/baander-log-query/token';
    public const string PROXY_SERVICE = 'docker-proxy';
    public const string PROXY_DIRECTORY = '/run/baander-docker-proxy';
    public const string PROXY_SOCKET = self::PROXY_DIRECTORY . '/docker.sock';
    public const string PROXY_CONFIGURATION = '/etc/baander-docker-proxy/nginx.conf';
    private const string DAEMON_SOCKET = '/run/baander-log-docker.sock';

    /**
     * The proxy's whole main configuration. The gateway image's own runs workers as an
     * unprivileged user, which the daemon's socket, root's and its group's alone, refuses.
     * One process (`master_process off`) stays root, the `user` its temporary directories are
     * given to, so the container needs no capability. GET and HEAD reach the five reads the agent issues,
     * matched on the normalized path that is also what Docker receives; every other path and
     * method answers 403. The agent speaks HTTP/1.0 and refuses a Transfer-Encoding, so
     * responses pass unbuffered and unchunked, and the endless event and followed log streams
     * meet the agent's own read deadline before any of the proxy's.
     */
    private const string PROXY_NGINX = <<<'NGINX'
daemon off;
master_process off;
user root root;
pid /tmp/nginx.pid;
error_log /dev/stderr warn;
events {
    worker_connections 64;
}
http {
    access_log off;
    server_tokens off;
    client_body_temp_path /tmp/client-body;
    proxy_temp_path /tmp/proxy;
    fastcgi_temp_path /tmp/fastcgi;
    uwsgi_temp_path /tmp/uwsgi;
    scgi_temp_path /tmp/scgi;
    upstream docker {
        server unix:@DAEMON@;
    }
    server {
        listen unix:@SOCKET@;
        location ~ "^(/v1\.[0-9]+)?/(info|containers/json|events|containers/[A-Za-z0-9][A-Za-z0-9_.-]*/(json|logs))$" {
            limit_except GET {
                deny all;
            }
            proxy_pass http://docker$uri$is_args$args;
            proxy_http_version 1.0;
            proxy_set_header Host docker;
            proxy_set_header Connection close;
            proxy_buffering off;
            proxy_request_buffering off;
            proxy_read_timeout 24h;
            proxy_send_timeout 24h;
        }
        location / {
            return 403;
        }
    }
}
NGINX;

    /** @return array{query:string,checkpoint:string} */
    public static function credentials(string $directory): array
    {
        PrivateDirectory::prepare($directory);
        $paths = ['query' => '', 'checkpoint' => ''];
        foreach (['query', 'checkpoint'] as $role) {
            $path = $directory . '/' . $role . '-token';
            if (is_link($path)) {
                throw new \RuntimeException('Refusing symlink log credentials.');
            }
            if (!file_exists($path)) {
                AtomicFile::write($path, bin2hex(random_bytes(32)) . "\n");
            }
            if (!is_file($path) || preg_match('/\A[0-9a-f]{64}\n?\z/D', (string) file_get_contents($path)) !== 1) {
                throw new \RuntimeException('Log collection credentials are not a valid generated token file.');
            }
            if ($role === 'query' && !chmod($path, 0444)) {
                throw new \RuntimeException('Cannot make the mounted query credential readable.');
            }
            $paths[$role] = $path;
        }
        return $paths;
    }

    /** @param list<array<string,mixed>> $groups
     * @return array<string,mixed>
     */
    public static function configuration(string $node, Target $target, array $groups): array
    {
        if (!str_starts_with($target->endpoint, 'unix:///')) {
            throw new \RuntimeException('Log collection requires an explicit daemon-visible Unix socket mapping.');
        }
        return [
            'nodeId' => $node, 'tokenFile' => '/run/baander-log-agent/query-token',
            'checkpointTokenFile' => '/run/baander-log-agent/checkpoint-token',
            'databasePath' => '/var/lib/baander-log-index/logs.sqlite',
            'dockerSocket' => self::PROXY_SOCKET, 'expectedDaemonId' => $target->daemonId,
            'containerGroups' => $groups,
            'fileRoots' => array_map(static fn(string $service): array => ['path' => self::ROOT . '/' . $service, 'service' => $service, 'optional' => true], ['web', 'control', 'execution']),
            'retentionDays' => 7, 'maxStorageBytes' => 1073741824,
        ];
    }

    /** @param array<string,mixed> $configuration
     * @return array<string,mixed>
     */
    public static function service(string $image, string $home, Target $target, string $credentials, array $configuration): array
    {
        return [
            'image' => $image, 'entrypoint' => ['php'], 'command' => [$home . '/bin/log-agent.php'],
            'user' => '0:0', 'restart' => 'unless-stopped', 'init' => true,
            'labels' => ['io.baander.log-agent' => 'true', 'io.baander.log-namespace' => $configuration['nodeId']],
            'environment' => [EnvironmentVariable::BAANDER_LOG_AGENT_CONFIG => json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)],
            'read_only' => true, 'tmpfs' => ['/tmp:rw,noexec,nosuid,nodev'],
            'cap_drop' => ['ALL'], 'cap_add' => ['DAC_OVERRIDE'], 'security_opt' => ['no-new-privileges:true', 'label=disable'],
            'volumes' => [
                ['type' => 'volume', 'source' => 'docker_proxy', 'target' => self::PROXY_DIRECTORY, 'read_only' => true],
                ['type' => 'bind', 'source' => $credentials, 'target' => '/run/baander-log-agent', 'read_only' => true, 'bind' => ['create_host_path' => false]],
                ['type' => 'volume', 'source' => 'logs', 'target' => self::ROOT, 'read_only' => true],
                ['type' => 'volume', 'source' => 'log_index', 'target' => '/var/lib/baander-log-index'],
            ],
        ];
    }

    /**
     * The Docker proxy's service. Root reaches the daemon's socket as its owner; the stale
     * socket of an unclean stop is removed before nginx binds its own.
     *
     * @return array<string,mixed>
     */
    public static function proxyService(string $image, Target $target, string $configurationVolume): array
    {
        if (!str_starts_with($target->endpoint, 'unix:///')) {
            throw new \RuntimeException('Log collection requires an explicit daemon-visible Unix socket mapping.');
        }

        return [
            'image' => $image, 'user' => '0:0', 'restart' => 'unless-stopped', 'init' => true,
            'entrypoint' => ['sh', '-ec', 'rm -f ' . self::PROXY_SOCKET . '; exec nginx -c ' . self::PROXY_CONFIGURATION],
            'network_mode' => 'none', 'read_only' => true, 'tmpfs' => ['/tmp:rw,noexec,nosuid,nodev'],
            'cap_drop' => ['ALL'], 'security_opt' => ['no-new-privileges:true', 'label=disable'],
            'volumes' => [
                ['type' => 'bind', 'source' => substr($target->endpoint, 7), 'target' => self::DAEMON_SOCKET, 'read_only' => true, 'bind' => ['create_host_path' => false]],
                ['type' => 'volume', 'source' => $configurationVolume, 'target' => dirname(self::PROXY_CONFIGURATION), 'read_only' => true],
                ['type' => 'volume', 'source' => 'docker_proxy', 'target' => self::PROXY_DIRECTORY],
            ],
            'oom_score_adj' => KillOrder::DOCKER_PROXY,
        ];
    }

    /** The proxy's nginx.conf; tests point it at sockets of their own. */
    public static function proxyConfiguration(string $daemonSocket = self::DAEMON_SOCKET, string $proxySocket = self::PROXY_SOCKET): string
    {
        return strtr(self::PROXY_NGINX, ['@DAEMON@' => $daemonSocket, '@SOCKET@' => $proxySocket]);
    }

    public static function nodes(string $node, string $additional = '[]'): string
    {
        $nodes = json_decode($additional, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($nodes) || !array_is_list($nodes)) {
            throw new \InvalidArgumentException('Configured log nodes require a JSON list.');
        }
        return json_encode([['id' => $node, 'url' => 'http://log-agent:9509', 'tokenFile' => self::QUERY_TOKEN], ...$nodes], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
