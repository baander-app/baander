<?php

declare(strict_types=1);

namespace Baander\Tooling\Deployment;

use Baander\Tooling\Docker\Target;
use Baander\Tooling\Filesystem\{AtomicFile, PrivateDirectory};

/** Host-owned collection credentials and an inventory pinned to the captured daemon. */
final class LogCollection
{
    public const string ROOT = '/var/log/baander';
    public const string QUERY_TOKEN = '/run/baander-log-query/token';

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
            'dockerSocket' => '/run/baander-log-docker.sock', 'expectedDaemonId' => $target->daemonId,
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
            'environment' => ['BAANDER_LOG_AGENT_CONFIG' => json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)],
            'read_only' => true, 'tmpfs' => ['/tmp:rw,noexec,nosuid,nodev'],
            'cap_drop' => ['ALL'], 'cap_add' => ['DAC_OVERRIDE'], 'security_opt' => ['no-new-privileges:true', 'label=disable'],
            'volumes' => [
                ['type' => 'bind', 'source' => substr($target->endpoint, 7), 'target' => '/run/baander-log-docker.sock', 'read_only' => true, 'bind' => ['create_host_path' => false]],
                ['type' => 'bind', 'source' => $credentials, 'target' => '/run/baander-log-agent', 'read_only' => true, 'bind' => ['create_host_path' => false]],
                ['type' => 'volume', 'source' => 'logs', 'target' => self::ROOT, 'read_only' => true],
                ['type' => 'volume', 'source' => 'log_index', 'target' => '/var/lib/baander-log-index'],
            ],
        ];
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
