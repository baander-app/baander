<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Filesystem\FileLock;
use Baander\Tooling\Filesystem\PrivateDirectory;

final class Gateway
{
    public readonly string $directory;
    public readonly string $configuration;

    public function __construct(private readonly StateStore $store, private readonly Runner $runner)
    {
        $this->directory = $store->root . '/gateway';
        $this->configuration = $this->directory . '/conf.d/routes.conf';
    }

    public function lock(): FileLock
    {
        PrivateDirectory::prepare($this->directory);
        return FileLock::acquire($this->directory . '/operation.lock', false);
    }

    /** @return array<string,mixed> */
    public static function ownedNetwork(Docker $docker, string $name, string $owner, bool $internal = false): array
    {
        $result = $docker->run(['network', 'inspect', $name], check: false);
        if ($result->exitCode !== 0) {
            $arguments = ['network', 'create', '--label', 'app.baander.development.owner=' . $owner];
            if ($internal) {
                $arguments[] = '--internal';
            }
            $docker->run([...$arguments, $name]);
            $result = $docker->run(['network', 'inspect', $name]);
        }
        try {
            $network = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR)[0] ?? null;
        } catch (\JsonException $error) {
            throw new WorkflowError('Cannot verify owned Docker network inventory.', previous: $error);
        }
        if (($network['Labels']['app.baander.development.owner'] ?? null) !== $owner) {
            throw new WorkflowError('Network is not owned by this workflow; select a different network name.');
        }
        $subnet = $network['IPAM']['Config'][0]['Subnet'] ?? null;
        if (!is_string($subnet)) {
            throw new WorkflowError('Cannot verify owned Docker network inventory.');
        }
        return ['name' => $name, 'subnet' => $subnet];
    }

    /** @param array<string,mixed> $instance
     * @param array<string,mixed> $release
     * @return array<string,mixed>
     */
    public function configure(array $instance, array $release): array
    {
        $lock = $this->lock();
        try {
            $docker = new Docker($this->runner, $instance['docker']);
            $docker->validate();
            $saved = $this->directory . '/state.json';
            $image = $release['nginx_image'];
            if (file_exists($saved)) {
                $record = AtomicFile::readJson($saved);
                $image = $record['nginx_image'];
                if ($record['docker'] != $instance['docker']) {
                    throw new WorkflowError('The shared gateway belongs to another Docker target.');
                }
            }
            $network = self::ownedNetwork($docker, 'baander-development-gateway-' . $this->store->scope(), 'gateway-' . $this->store->scope());
            $base = explode('/', $network['subnet'])[0];
            $numeric = ip2long($base);
            if ($numeric === false || !str_contains($network['subnet'], '/')) {
                throw new WorkflowError('Cannot verify owned Docker network inventory.');
            }
            $prefix = (int) explode('/', $network['subnet'])[1];
            if ($prefix < 0 || $prefix > 30) {
                throw new WorkflowError('Cannot verify owned Docker network inventory.');
            }
            $address = long2ip(($numeric & (-1 << (32 - $prefix))) + 2);
            $gateway = [...$network, 'ipv4_address' => $address];
            PrivateDirectory::prepare(dirname($this->configuration));
            if (!file_exists($this->configuration)) {
                AtomicFile::write($this->configuration, $this->render([]));
            }
            $project = 'baander-development-gateway-' . $this->store->scope();
            $compose = [
                'name' => $project,
                'services' => ['gateway' => [
                    'image' => $image,
                    'ports' => ['127.0.0.1:443:443'],
                    'volumes' => [
                        self::bind(dirname($this->configuration), '/etc/nginx/conf.d'),
                        self::bind($this->directory . '/tls', '/etc/nginx/certificates'),
                    ],
                    'networks' => ['gateway' => ['ipv4_address' => $address]],
                ]],
                'networks' => ['gateway' => ['external' => true, 'name' => $network['name']]],
            ];
            AtomicFile::writeJson($this->directory . '/compose.json', $compose);
            $record = [
                'docker' => $instance['docker'],
                'project' => $project,
                'compose_file' => $this->directory . '/compose.json',
                'gateway' => $gateway,
                'nginx_image' => $image,
            ];
            AtomicFile::writeJson($saved, $record);
            return $gateway;
        } finally {
            $lock->release();
        }
    }

    /** @return array<string,mixed> */
    private static function bind(string $source, string $target): array
    {
        return [
            'type' => 'bind',
            'source' => str_replace('$', '$$', $source),
            'target' => $target,
            'read_only' => true,
            'bind' => ['create_host_path' => false],
        ];
    }

    public function start(): void
    {
        $lock = $this->lock();
        try {
            $record = $this->record();
            foreach (['cert.pem', 'key.pem'] as $name) {
                if (!is_file($this->directory . '/tls/' . $name)) {
                    throw new WorkflowError('Run explicit host certificate setup before starting the gateway.');
                }
            }
            new Docker($this->runner, $record['docker'])->compose($record, ['up', '-d', '--wait']);
        } finally {
            $lock->release();
        }
    }

    /** @return array<string,mixed> */
    public function record(): array
    {
        try {
            return AtomicFile::readJson($this->directory . '/state.json');
        } catch (\RuntimeException $error) {
            throw new WorkflowError('Configure the shared development gateway first.', previous: $error);
        }
    }

    /** @param array<string,array<string,mixed>> $routes */
    public function render(array $routes): string
    {
        $template = (string) file_get_contents(dirname(__DIR__, 4) . '/docker/dev/gateway.conf.template');
        $servers = [];
        ksort($routes);
        foreach ($routes as $hostname => $route) {
            if (preg_match('/\A(?:[a-z0-9-]+\.)?baander\.test\z/', $hostname) !== 1) {
                throw new WorkflowError('Gateway hostname must be baander.test or one preview label below it.');
            }
            $upstream = $route['upstream'];
            if (preg_match('/\A[a-z0-9][a-z0-9_-]*-frontend\z/', $upstream) !== 1) {
                throw new WorkflowError('Invalid gateway upstream alias.');
            }
            $location = $route['maintenance'] ? 'return 503;' : 'set $backend http://' . $upstream . ':80; proxy_pass $backend;';
            $servers[] = str_replace(['${SERVER_NAME}', '${LOCATION}'], [$hostname, $location], $template);
        }
        return "map \$http_upgrade \$connection_upgrade { default upgrade; '' close; }\n"
            . "resolver 127.0.0.11 valid=5s;\n"
            . 'server { listen 443 ssl default_server; server_name _; ssl_certificate /etc/nginx/certificates/cert.pem; '
            . "ssl_certificate_key /etc/nginx/certificates/key.pem; return 404; }\n" . implode("\n", $servers);
    }

    /** @param array<string,mixed> $instance */
    public function route(array $instance, bool $maintenance = false): void
    {
        $this->change($instance, $maintenance);
    }

    /** @param array<string,mixed> $instance */
    public function remove(array $instance): void
    {
        $this->change($instance, null);
    }

    /** @param array<string,mixed> $instance */
    private function change(array $instance, ?bool $maintenance): void
    {
        $lock = $this->lock();
        try {
            $record = $this->record();
            if ($instance['docker'] != $record['docker']) {
                throw new WorkflowError('Route target differs from the shared gateway Docker target.');
            }
            $path = $this->directory . '/routes.json';
            $routes = file_exists($path) ? AtomicFile::readJson($path) : [];
            $hostname = $instance['hostname'];
            if (isset($routes[$hostname]) && $routes[$hostname]['owner'] !== $instance['id']) {
                throw new WorkflowError('Hostname is already owned by another instance.');
            }
            if ($maintenance === null) {
                unset($routes[$hostname]);
            } else {
                $routes[$hostname] = ['owner' => $instance['id'], 'upstream' => $instance['project'] . '-frontend', 'maintenance' => $maintenance];
            }
            $candidate = $this->directory . '/candidate.conf';
            AtomicFile::write($candidate, $this->render($routes));
            $docker = new Docker($this->runner, $record['docker']);
            $docker->validate();
            $docker->run([
                'run',
                '--rm',
                '--network',
                'none',
                '--mount',
                'type=bind,src=' . $candidate . ',dst=/etc/nginx/conf.d/default.conf,readonly',
                '--mount',
                'type=bind,src=' . $this->directory . '/tls,dst=/etc/nginx/certificates,readonly',
                $record['nginx_image'],
                'nginx',
                '-t',
            ]);
            $old = (string) file_get_contents($this->configuration);
            AtomicFile::write($this->configuration, (string) file_get_contents($candidate));
            try {
                $docker->compose($record, ['exec', '-T', 'gateway', 'nginx', '-t']);
                $docker->compose($record, ['exec', '-T', 'gateway', 'nginx', '-s', 'reload']);
            } catch (WorkflowError $error) {
                AtomicFile::write($this->configuration, $old);
                throw $error;
            }
            // The route document is a hostname-keyed object, including when empty.
            AtomicFile::write($path, json_encode((object) $routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            unlink($candidate);
        } finally {
            $lock->release();
        }
    }
}
