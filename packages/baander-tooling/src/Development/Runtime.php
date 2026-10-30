<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

use Baander\Tooling\Process\{CommandExecutor, ProcessSpec, CompletionPolicy};
use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Filesystem\PrivateDirectory;
use Baander\Tooling\Resources\DisposableContainer;

class Runtime
{
    private readonly Connections $connections;

    public function __construct(private readonly StateStore $store, private readonly CommandExecutor $runner, ?Connections $connections = null)
    {
        $this->connections = $connections ?? new Connections($runner, Environment::clean());
    }

    /** @param array<string,mixed> $instance
     * @return array<string,string>
     */
    public function environment(array $instance): array
    {
        $path = $this->store->directory($instance['id']) . '/env.json';
        if (is_link($path)) {
            throw new WorkflowError('Refusing symlink environment configuration.');
        }
        try {
            $content = @file_get_contents($path);
            $object = json_decode($content === false ? '' : $content, flags: JSON_THROW_ON_ERROR);
            if (!$object instanceof \stdClass) {
                throw new \UnexpectedValueException('Expected string environment mapping');
            }
            $values = [];
            foreach (get_object_vars($object) as $key => $value) {
                if (!is_string($value)) {
                    throw new \UnexpectedValueException('Expected string environment mapping');
                }
                $values[$key] = $value;
            }
            return $values;
        } catch (\Throwable $error) {
            throw new WorkflowError(
                'Configure private env.json before starting this instance.',
                previous: $error,
            );
        }
    }

    /** @return array<string,mixed> */
    public static function bind(string $source, string $target, bool $readOnly = true): array
    {
        return [
            'type' => 'bind',
            'source' => str_replace('$', '$$', $source),
            'target' => $target,
            'read_only' => $readOnly,
            'bind' => ['create_host_path' => false],
        ];
    }

    /** @return array<string,string> */
    public static function volume(string $name, string $target): array
    {
        return [
            'type' => 'volume',
            'source' => $name,
            'target' => $target,
        ];
    }

    /** @param array<string,mixed> $instance
     * @param array<string,mixed> $release
     * @return array<string,mixed>
     */
    public function config(array $instance, array $release): array
    {
        $home = \Baander\Tooling\Configuration\ApplicationLayout::fromArray($release['layout'])->root;
        $project = $instance['project'];
        if (!preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $project)) {
            throw new WorkflowError('Invalid Compose project name.');
        }
        $environment = $this->environment($instance);
        $origin = 'https://' . $instance['hostname'];
        $environment = array_replace($environment, [
            'APP_URL' => $origin,
            'DEFAULT_URI' => $origin,
            'APP_DOMAIN' => $instance['hostname'],
            'TRUSTED_PROXIES' => $instance['proxy_ip'] ?? '',
            'DISCOVERY_ENABLED' => 'false',
        ]);
        $mounts = [
            self::volume('var', $home . '/var'),
            self::volume('secrets', $home . '/config/secrets'),
            self::volume('media', $environment['MEDIA_STORAGE_PATH'] ?? '/storage/media'),
            self::volume('storage', $home . '/storage'),
            self::volume('conversions', $environment['CONVERT_STORAGE_PATH'] ?? '/storage/conversions'),
            self::volume('transcode_state', $home . '/var/transcode_state'),
            self::volume('public', $home . '/public'),
        ];
        foreach ($instance['media'] ?? [] as $media) {
            $source = $media['source'];
            $target = $media['target'];
            if (!str_starts_with($source, '/') || !is_dir($source) || !str_starts_with($target, '/')) {
                throw new WorkflowError('Configure an existing absolute media directory and absolute container path.');
            }
            if (
                $target === $home
                || str_starts_with($target, $home . '/')
                || in_array($target, ['/', '/var/run', '/run', '/etc'], true)
            ) {
                throw new WorkflowError('Media mount overlaps application or system state.');
            }
            if ($target === ($environment['MEDIA_STORAGE_PATH'] ?? '/storage/media')) {
                $mounts = $mounts
                    |> (static fn(array $mounts): array => array_filter(
                        $mounts,
                        static fn(array $mount): bool => $mount['target'] !== $target,
                    ))
                    |> array_values(...);
            }
            $mounts[] = self::bind($source, $target);
        }
        $services = [
            'app' => [
                'image' => $release['images']['app_image']['resolvedId'],
                'environment' => $environment,
                'volumes' => $mounts,
                'stop_grace_period' => '35s',
                'networks' => ['private' => new \stdClass()],
                'init' => true,
            ],
            'postgres' => [
                'image' => $release['images']['postgres_image']['resolvedId'],
                'environment' => [
                    'POSTGRES_USER' => $environment['DB_USERNAME'] ?? 'baander',
                    'POSTGRES_PASSWORD' => $environment['DB_PASSWORD'] ?? 'baander',
                    'POSTGRES_DB' => $environment['DB_DATABASE'] ?? 'baander',
                    'PGDATA' => $instance['pgdata'] ?? '/var/lib/postgresql/data/baanderdev',
                ],
                'volumes' => [self::volume(
                    'database',
                    $instance['postgres_mount_target'] ?? '/var/lib/postgresql/data',
                )],
                'networks' => ['private' => ['aliases' => ['database']]],
                'healthcheck' => [
                    'test' => ['CMD-SHELL', 'pg_isready -U "$$POSTGRES_USER" -d "$$POSTGRES_DB"'],
                    'interval' => '5s',
                    'timeout' => '5s',
                    'retries' => 12,
                ],
            ],
            'redis' => [
                'image' => $release['images']['redis_image']['resolvedId'],
                'environment' => ['REDIS_PASSWORD' => $environment['REDIS_PASSWORD'] ?? ''],
                'command' => [
                    'sh',
                    '-ec',
                    'exec redis-server --dir /data --appendonly yes --maxmemory-policy noeviction --requirepass "$$REDIS_PASSWORD"',
                ],
                'volumes' => [self::volume('redis', '/data')],
                'networks' => ['private' => new \stdClass()],
            ],
            'nginx' => [
                'image' => $release['images']['nginx_image']['resolvedId'],
                'environment' => [
                    'SERVER_NAME' => $instance['hostname'],
                    'APP_HOME' => $home,
                    'GATEWAY_ADDRESS' => $instance['gateway']['ipv4_address'] ?? '',
                ],
                'volumes' => [
                    self::bind(
                        $this->store->directory($instance['id']) . '/frontend.conf.template',
                        '/etc/nginx/templates/default.conf.template',
                    ),
                    [...self::volume('public', $home . '/public'), 'read_only' => true],
                ],
                'networks' => [
                    'private' => new \stdClass(),
                    'gateway' => ['aliases' => [$project . '-frontend']],
                ],
            ],
        ];
        $postgresTarget = rtrim($instance['postgres_mount_target'] ?? '/var/lib/postgresql/data', '/');
        if (
            $postgresTarget !== ''
            && $postgresTarget !== '/var/lib/postgresql'
            && !str_starts_with('/var/lib/postgresql', $postgresTarget . '/')
        ) {
            $services['postgres']['tmpfs'] = ['/var/lib/postgresql:rw,noexec,nosuid,size=1m,mode=0755'];
        }
        if ($instance['kind'] === 'preview') {
            $environment = array_replace(
                $environment,
                ['BAANDER_DEV_ORIGIN' => $origin, 'APP_ENV' => 'dev', 'APP_DEBUG' => '1'],
            );
            $services['app']['environment'] = $environment;
            $source = \Baander\Tooling\Filesystem\Paths::resolvedPath($instance['worktree']);
            array_unshift($mounts, self::bind($source, $home));
            $mounts[] = self::volume('vendor', $home . '/vendor');
            $services['app']['volumes'] = $mounts;
            $services['vite'] = [
                'image' => $release['images']['tool_image']['resolvedId'],
                'working_dir' => $home . '/ui/web',
                'entrypoint' => ['yarn'],
                'command' => ['dev', '--host', '0.0.0.0'],
                'environment' => [
                    'BAANDER_DEV_ORIGIN' => $origin,
                    'YARN_INSTALL_STATE_PATH' => $home . '/ui/web/node_modules/.yarn-install-state.gz',
                ],
                'volumes' => [
                    self::bind($source, $home),
                    self::volume('node_modules', $home . '/ui/web/node_modules'),
                    self::volume('public', $home . '/public'),
                ],
                'networks' => ['private' => new \stdClass()],
            ];
        }
        $defaults = [
            'var' => $home . '/var',
            'secrets' => $home . '/config/secrets',
            'media' => $environment['MEDIA_STORAGE_PATH'] ?? '/storage/media',
            'storage' => $home . '/storage',
            'conversions' => $environment['CONVERT_STORAGE_PATH'] ?? '/storage/conversions',
            'redis' => '/data',
            'transcode_state' => $home . '/var/transcode_state',
        ];
        foreach ($instance['state_mounts'] ?? [] as $key => $saved) {
            $service = $key === 'redis' ? 'redis' : 'app';
            $services[$service]['volumes'] = $services[$service]['volumes']
                |> (static fn(array $mounts): array => array_filter(
                    $mounts,
                    static fn(array $mount): bool => $mount['target'] !== ($defaults[$key] ?? null),
                ))
                |> array_values(...);
            $services[$service]['volumes'][] = $saved['type'] === 'bind'
                ? self::bind($saved['source'], $saved['target'], $key === 'media')
                : ($key === 'media'
                    ? [...self::volume($key, $saved['target']), 'read_only' => true]
                    : self::volume($key, $saved['target']));
        }
        foreach ($services as &$service) {
            $service['environment'] = array_map(
                static fn(string $value): string => str_replace('$', '$$', $value),
                $service['environment'],
            );
        }
        unset($service);
        $volumes = [];
        foreach ([
            'database',
            'redis',
            'var',
            'secrets',
            'media',
            'storage',
            'conversions',
            'transcode_state',
            'public',
            'vendor',
            'node_modules',
        ] as $key) {
            $volumes[$key] = ['name' => $instance['volumes'][$key] ?? $project . '-' . $key];
            if (isset($instance['volumes'][$key])) {
                $volumes[$key]['external'] = true;
            }
        }
        return [
            'name' => $project,
            'services' => $services,
            'networks' => [
                'private' => ['name' => self::networkName($instance), 'external' => true],
                'gateway' => [
                    'external' => true,
                    'name' => $instance['gateway']['name'] ?? 'baander-development-gateway',
                ],
            ],
            'volumes' => $volumes,
        ];
    }

    /** @param array<string,mixed> $instance */
    public static function networkName(array $instance): string
    {
        $network = $instance['network'] ?? $instance['project'] . '-private';
        return is_array($network) ? $network['name'] : $network;
    }

    /** @param array<string,mixed> $instance
     * @param array<string,mixed> $release
     * @param list<string> $command
     * @return array{arguments:list<string>,environment:array<string,string>}
     */
    public function applicationContainer(array $instance, array $release, array $command): array
    {
        $model = $this->config($instance, $release);
        $application = $model['services']['app'];
        $arguments = ['--init', '--network', self::networkName($instance), '--workdir', ApplicationLayout::fromArray($release['layout'])->root];
        foreach ($application['volumes'] as $mount) {
            $source = $mount['type'] === 'volume' ? $model['volumes'][$mount['source']]['name'] : str_replace('$$', '$', $mount['source']);
            $arguments[] = '--mount';
            $arguments[] = 'type=' . $mount['type'] . ',src=' . $source . ',dst=' . $mount['target'] . (($mount['read_only'] ?? false) ? ',readonly' : '');
        }
        $environment = [];
        foreach ($application['environment'] as $key => $value) {
            $environment[$key] = str_replace('$$', '$', $value);
            $arguments[] = '--env';
            $arguments[] = $key;
        }
        return ['arguments' => [...$arguments, $release['images']['app_image']['resolvedId'], ...$command], 'environment' => $environment];
    }

    /** @param array<string,mixed> $instance
     * @param array<string,mixed> $release
     * @return array<string,mixed>
     */
    public function ensureNetworks(array $instance, array $release): array
    {
        $docker = $this->connections->client($instance['docker']);
        $docker->validateIdentity();
        $network = Gateway::ownedNetwork($docker, self::networkName($instance), $instance['id']);
        $instance['network'] = $network;
        $this->store->save($instance['id'], $instance);
        $gateway = new Gateway($this->store, $this->runner, $this->connections)->configure($instance, $release);
        $instance['gateway'] = $gateway;
        $this->store->save($instance['id'], $instance);
        if ($instance['kind'] === 'preview') {
            $source = \Baander\Tooling\Filesystem\Paths::resolvedPath($instance['worktree']);
            $expected = [];
            foreach (['composer.lock', 'ui/web/package.json'] as $name) {
                $hash = @hash_file('sha256', $source . '/' . $name);
                if ($hash === false) {
                    throw new WorkflowError('Preview source must be a complete Baander worktree.');
                }
                $expected[] = $hash;
            }
            try {
                $result = DisposableContainer::run($docker, $this->store->directory($instance['id']) . '/runtime-source-probe.json', [
                    '--network',
                    'none',
                    '--entrypoint',
                    '/bin/sh',
                    '--mount',
                    'type=bind,src=' . $source . ',dst=/probe,readonly',
                    $release['images']['app_image']['resolvedId'],
                    '-ec',
                    'sha256sum /probe/composer.lock /probe/ui/web/package.json',
                ], $this->connections->environment(), $release['effective']['budgets']['cleanup'] ?? 120);
            } catch (\RuntimeException $error) {
                throw new WorkflowError(
                    'Preview checkout is unavailable to the selected Docker daemon; check daemon path sharing, Unix permissions and SELinux read labels. ' . $error->getMessage(),
                    previous: $error,
                );
            }
            $actual = $result->stdout
                |> trim(...)
                |> (static fn(string $output): array => explode("\n", $output))
                |> (static fn(array $lines): array => array_filter(
                    $lines,
                    static fn(string $line): bool => trim($line) !== '',
                ))
                |> array_values(...)
                |> (static fn(array $lines): array => array_map(
                    static fn(string $line): string => preg_split('/\s+/', trim($line))[0],
                    $lines,
                ));
            if ($actual !== $expected) {
                throw new WorkflowError('Docker sees different preview source at this path; check daemon path sharing, Unix permissions and SELinux read labels.');
            }
        }
        foreach ($instance['media'] ?? [] as $media) {
            $source = $media['source'];
            if (!str_starts_with($source, '/') || !is_dir($source)) {
                throw new WorkflowError('Configure an existing absolute media directory.');
            }
            DisposableContainer::run($docker, $this->store->directory($instance['id']) . '/runtime-media-probe-' . hash('sha256', $media['target']) . '.json', [
                '--network',
                'none',
                '--entrypoint',
                '/bin/sh',
                '--mount',
                'type=bind,src=' . $source . ',dst=/probe,readonly',
                $release['images']['app_image']['resolvedId'],
                '-ec',
                'test -d /probe && test -r /probe && test -x /probe',
            ], $this->connections->environment(), $release['effective']['budgets']['cleanup'] ?? 120);
        }
        return $instance;
    }

    /** @param array<string,mixed> $instance
     * @param array<string,mixed> $release
     * @return array<string,mixed>
     */
    public function write(array $instance, array $release): array
    {
        $instance = $this->ensureNetworks($instance, $release);
        $directory = $this->store->directory($instance['id']);
        PrivateDirectory::prepare($directory);
        $content = file_get_contents($release['source'] . '/docker/dev/frontend.conf.template');
        if ($content === false) {
            throw new WorkflowError('Cannot read frontend configuration template.');
        }
        $preview = $instance['kind'] === 'preview' ? 'location /@ { proxy_pass $vite_backend; }
    location /src/ { proxy_pass $vite_backend; }
    location /node_modules/ { proxy_pass $vite_backend; }
    location /__vite_hmr { proxy_pass $vite_backend; }' : '';
        AtomicFile::write(
            $directory . '/frontend.conf.template',
            str_replace('include /etc/nginx/preview.conf;', $preview, $content),
        );
        AtomicFile::writeJson($directory . '/compose.json', $this->config($instance, $release));
        $files = [];
        foreach ([$directory . '/compose.json', $directory . '/frontend.conf.template'] as $path) {
            $digest = hash_file('sha256', $path);
            if ($digest === false) {
                throw new WorkflowError('Cannot identify generated runtime input: ' . $path);
            }
            $files[$path] = $digest;
        }
        $instance['compose_file'] = $directory . '/compose.json';
        $instance['generated_inputs'] = ['release' => $release['sourceDigest'], 'files' => $files];
        $this->store->save($instance['id'], $instance);
        return $instance;
    }

    /** @param array<string,mixed> $instance */
    public function running(array $instance): bool
    {
        $docker = $this->connections->client($instance['docker']);
        $docker->validateIdentity();
        $services = ['app', 'nginx', 'postgres', 'redis'];
        if ($instance['kind'] === 'preview') {
            $services[] = 'vite';
        }
        sort($services);
        $identifiers = preg_split(
            '/\s+/',
            ($docker->compose($instance['project'], $instance['compose_file'], ['ps', '--all', '--quiet', ...$services])->stdout
                |> trim(...)),
            flags: PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        if ($identifiers === []) {
            return false;
        }
        $containers = ($docker->run(['inspect', ...$identifiers])->stdout
            |> (static fn(string $output): mixed => json_decode($output, true, flags: JSON_THROW_ON_ERROR)));
        $observed = [];
        $running = true;
        foreach ($containers as $container) {
            $labels = $container['Config']['Labels'];
            $service = $labels['com.docker.compose.service'] ?? null;
            if (
                ($labels['com.docker.compose.project'] ?? null) !== $instance['project']
                || !in_array($service, $services, true)
                || in_array($service, $observed, true)
            ) {
                throw new WorkflowError('Runtime observation does not identify the owned service set.');
            }
            $observed[] = $service;
            $state = $container['State']['Running'];
            if (!is_bool($state)) {
                throw new WorkflowError('Runtime container state was not confirmed.');
            }
            $running = $running && $state;
        }
        sort($observed);
        return $observed === $services && $running;
    }

    /** @param array<string,mixed> $instance */
    public function dependencies(array $instance): void
    {
        $docker = $this->connections->client($instance['docker']);
        $docker->validateIdentity();
        $docker->compose($instance['project'], $instance['compose_file'], ['up', '-d', '--wait', 'postgres', 'redis']);
    }

    /** @param array<string,mixed> $instance
     * @param array<string,mixed> $release
     */
    public function prepare(array $instance, array $release): void
    {
        $home = \Baander\Tooling\Configuration\ApplicationLayout::fromArray($release['layout'])->root;
        $docker = $this->connections->client($instance['docker']);
        $docker->validateIdentity();
        $model = $this->config($instance, $release);
        if ($instance['kind'] === 'preview') {
            $root = realpath($instance['worktree']);
            if ($root === false) {
                throw new WorkflowError('Preview source must be a complete Baander worktree.');
            }
            $targets = [];
            foreach ($model['services'] as $service) {
                foreach ($service['volumes'] ?? [] as $mount) {
                    if (
                        $mount['type'] === 'volume'
                        && ($mount['target'] === $home || str_starts_with($mount['target'], $home . '/'))
                    ) {
                        $target = substr($mount['target'], strlen($home . '/'));
                        if (in_array('..', explode('/', $target), true)) {
                            throw new WorkflowError('Preview volume mountpoints must not contain parent segments.');
                        }
                        $current = $root;
                        foreach (explode('/', $target) as $part) {
                            $current .= '/' . $part;
                            if (is_link($current)) {
                                throw new WorkflowError('Preview volume mountpoint contains a symlink; use checkout directories.');
                            }
                        }
                        if (file_exists($root . '/' . $target) && !is_dir($root . '/' . $target)) {
                            throw new WorkflowError('Preview volume mountpoint is not a directory.');
                        }
                        $targets[$target] = true;
                    }
                }
            }
            foreach (array_keys($targets) as $target) {
                if (!is_dir($root . '/' . $target) && !mkdir($root . '/' . $target, 0777, true)) {
                    throw new WorkflowError('Cannot create preview volume mountpoint.');
                }
            }
        }
        $destinations = [
            'public' => $home . '/public',
            'var' => $home . '/var',
            'storage' => $home . '/storage',
            'conversions' => '/storage/conversions',
            'transcode_state' => $home . '/var/transcode_state',
            'secrets' => $home . '/config/secrets',
        ];
        if ($instance['kind'] === 'preview') {
            $destinations['vendor'] = $home . '/vendor';
        }
        foreach ($model['services']['app']['volumes'] as $mount) {
            if ($mount['type'] === 'volume' && $mount['source'] === 'media'
                && !($mount['read_only'] ?? false) && !($model['volumes']['media']['external'] ?? false)) {
                $destinations['media'] = $mount['target'];
            }
        }
        foreach ($destinations as $key => $source) {
            $name = $model['volumes'][$key]['name'];
            $script = match ($key) {
                'public', 'vendor' => 'find /seed -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +; cp -a ' . $source . '/. /seed/; ',
                'secrets' => 'if [ -z "$(ls -A /seed)" ] && [ -d ' . $source . ' ]; then cp -a ' . $source . '/. /seed/; fi; ',
                'var' => 'rm -rf /seed/cache; mkdir -p /seed/cache /seed/log; ',
                default => '',
            };
            $script .= 'chown '
                . (in_array($key, ['public', 'vendor', 'var', 'secrets'], true) ? '-R ' : '')
                . 'www-data:www-data /seed';
            DisposableContainer::run($docker, $this->store->directory($instance['id']) . '/runtime-seed-' . $key . '.json', [
                '--network',
                'none',
                '--user',
                '0:0',
                '--entrypoint',
                '/bin/sh',
                '--mount',
                'type=volume,src=' . $name . ',dst=/seed',
                $release['images']['app_image']['resolvedId'],
                '-ec',
                $script,
            ], $this->connections->environment(), $release['effective']['budgets']['cleanup'] ?? 120);
        }
        if ($instance['kind'] === 'preview') {
            $source = \Baander\Tooling\Filesystem\Paths::resolvedPath($instance['worktree']);
            $modules = $model['volumes']['node_modules']['name'];
            DisposableContainer::run($docker, $this->store->directory($instance['id']) . '/runtime-yarn.json', [
                '--entrypoint',
                'yarn',
                '--workdir',
                $home . '/ui/web',
                '--mount',
                'type=bind,src=' . $source . ',dst=' . $home . ',readonly',
                '--mount',
                'type=volume,src=' . $modules . ',dst=' . $home . '/ui/web/node_modules',
                '--env',
                'YARN_ENABLE_IMMUTABLE_INSTALLS=true',
                '--env',
                'YARN_INSTALL_STATE_PATH=' . $home . '/ui/web/node_modules/.yarn-install-state.gz',
                '--env',
                'YARN_CACHE_FOLDER=' . $home . '/ui/web/node_modules/.yarn-cache',
                $release['images']['tool_image']['resolvedId'],
                'install',
                '--immutable',
            ], $this->connections->environment(), $release['effective']['budgets']['cleanup'] ?? 120, timeout: 900);
        }
    }

    /** @param array<string,mixed> $instance
     * @param array<string,mixed> $release
     */
    public function up(array &$instance, array $release): void
    {
        $docker = $this->connections->client($instance['docker']);
        $docker->validateIdentity();
        $this->prepare($instance, $release);
        $docker->compose($instance['project'], $instance['compose_file'], ['up', '-d', '--no-deps', 'nginx']);
        $identifier = ($docker->compose($instance['project'], $instance['compose_file'], ['ps', '-q', 'nginx'])->stdout
            |> trim(...));
        if ($identifier === '') {
            throw new WorkflowError('Frontend did not start; inspect this instance logs.');
        }
        $networks = ($docker->run(['inspect', '--format', '{{json .NetworkSettings.Networks}}', $identifier])->stdout
            |> (static fn(string $output): mixed => json_decode($output, true, flags: JSON_THROW_ON_ERROR)));
        $proxyIp = $networks[self::networkName($instance)]['IPAddress'];
        $instance = $this->write(array_replace($instance, ['proxy_ip' => $proxyIp]), $release);
        $docker->compose(
            $instance['project'],
            $instance['compose_file'],
            ['up', '-d', '--no-deps', 'app', ...($instance['kind'] === 'preview' ? ['vite'] : [])],
        );
    }

    /** @param array<string,mixed> $instance */
    public function down(array $instance): void
    {
        $docker = $this->connections->client($instance['docker']);
        $docker->validateIdentity();
        $docker->compose($instance['project'], $instance['compose_file'], ['down', '--remove-orphans']);
    }

    /** @param array<string,mixed> $instance
     * @return array{url:string,web_ready:bool}
     */
    public function ready(array $instance): array
    {
        $origin = 'https://' . $instance['hostname'];
        if ($this->runner->run(new ProcessSpec(['curl', '--fail', '--silent', '--show-error', '--max-time', '15', $origin . '/ready'], processGroup: true), new CompletionPolicy(null))->exitCode !== 0) {
            throw new WorkflowError($origin . ' is not ready; inspect instance logs, DNS and certificate trust.');
        }
        return ['url' => $origin, 'web_ready' => true];
    }
}
