<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Web;

use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Docker\Target;
use Baander\Tooling\Filesystem\{AtomicFile, PrivateDirectory};
use Baander\Tooling\Fixtures\Runtime;
use Baander\Tooling\Process\ProcessResult;

final class CapacityWorkflow
{
    public static function main(string $root, string $frontend): int
    {
        Runtime::require((getenv('BAANDER_TEST_CHECKOUT_IN_IMAGE') ?: '') === '1', 'Capacity requires a CI image containing its exact checkout.');
        Runtime::require((getenv('BAANDER_TEST_IMAGE') ?: '') !== '', 'Select the built CI image with BAANDER_TEST_IMAGE.');
        $workflow = new RuntimeWorkflow($root, capacity: true);
        return $workflow->execute(static function (RuntimeWorkflow $web) use ($root, $frontend): void {
            self::startIngress($web);
            $inspection = json_decode($web->docker(['inspect', $web->id . '-app'])->stdout, true, flags: JSON_THROW_ON_ERROR)[0];
            $identityPath = $web->artifacts . '/capacity-runtime-identity.json';
            $manifestPath = $web->artifacts . '/capacity-manifest.json';
            $identity = json_decode($web->app(['php', '-r', <<<'PHP'
echo json_encode([
    'php' => PHP_VERSION,
    'swoole' => phpversion('swoole'),
    'environment' => getenv('APP_ENV'),
    'debug' => getenv('APP_DEBUG'),
    'memoryLimit' => ini_get('memory_limit'),
    'frontendManifestSha256' => hash_file('sha256', 'public/.vite/manifest.json'),
    'fixtureSha256' => hash_file('sha256', 'tests/Fixtures/LiveUpdates/capacity-fixture.php'),
    'entrypoints' => json_decode(file_get_contents('public/.vite/entrypoints.json'), true, flags: JSON_THROW_ON_ERROR),
], JSON_THROW_ON_ERROR);
PHP])->stdout, true, flags: JSON_THROW_ON_ERROR);
            Runtime::require($identity['environment'] === 'prod' && $identity['debug'] === '0', 'Capacity must use production mode with debugging disabled.');
            Runtime::require(is_string($identity['frontendManifestSha256']) && strlen($identity['frontendManifestSha256']) === 64, 'The CI image must contain the built frontend manifest.');
            Runtime::require(array_key_exists('viteServer', $identity['entrypoints']) && $identity['entrypoints']['viteServer'] === null, 'Capacity must serve the built frontend without Vite.');
            Runtime::require(hash_equals(hash_file('sha256', $root . '/tests/Fixtures/LiveUpdates/capacity-fixture.php'), $identity['fixtureSha256']), 'The CI image contains a different capacity fixture; rebuild it.');
            Runtime::require(hash_equals(hash_file('sha256', $root . '/public/.vite/manifest.json'), $identity['frontendManifestSha256']), 'The CI image contains a different frontend build; rebuild it.');
            $sourceDigest = $web->app(['php', '-r', 'require "scripts/bootstrap.php"; echo Baander\\Tooling\\Fixtures\\Web\\CapacityWorkflow::sourceDigest(getcwd());'])->stdout;
            Runtime::require(hash_equals(self::sourceDigest($root), $sourceDigest), 'The CI image contains different backend sources or configuration; rebuild it.');
            $image = $web->runtime->image('application');
            Runtime::require($inspection['Image'] === $image->id, 'The capacity container differs from the selected immutable image.');
            $source = $web->runtime->run(['git', 'rev-parse', 'HEAD'], cwd: $root)->stdout |> trim(...);
            AtomicFile::writeJson($identityPath, [
                'version' => 1,
                ...$identity,
                'frontend' => 'built',
                'imageId' => $image->id,
                'selectedImage' => $image->selection->reference,
                'dockerDaemonId' => $web->docker->target->daemonId,
                'containerId' => $inspection['Id'],
                'checkoutHead' => $source,
                'backendSourceSha256' => $sourceDigest,
                'baseUrl' => $web->capacityOrigin(),
            ]);
            AtomicFile::writeJson($manifestPath, [
                'version' => 1,
                'containerId' => $inspection['Id'],
                'imageId' => $image->id,
                'ledger' => $web->artifacts . '/resources.json',
                'appDirectory' => $web->appDirectory,
                'baseUrl' => $web->capacityOrigin(),
                'identityPath' => $identityPath,
            ]);
            fwrite(STDOUT, 'Capacity runtime identity: ' . $identityPath . "\n");
            $browserLog = $web->artifacts . '/capacity-browser.log';
            fwrite(STDOUT, 'Capacity browser log: ' . $browserLog . "\n");
            $web->runtime->run([
                $frontend, 'yarn', '--cwd', 'ui/web', 'e2e:headless',
                'tests/e2e/live-updates/capacity.spec.ts',
                'tests/e2e/live-updates/populated-views.spec.ts', '--retries=0',
            ], cwd: $root, env: array_replace($web->runtime->environment, [
                'E2E_BASE_URL' => $web->capacityOrigin(),
                'BAANDER_LIVE_CAPACITY_MANIFEST' => $manifestPath,
                'BAANDER_LIVE_CAPACITY_IDENTITY' => $identityPath,
            ]), logPath: $browserLog);
        });
    }

    public static function sourceDigest(string $root): string
    {
        $paths = ['src', 'config', 'migrations'];
        foreach (new \DirectoryIterator($root . '/packages') as $package) {
            if (!$package->isDot() && $package->isDir() && is_dir($package->getPathname() . '/src')) {
                $paths[] = 'packages/' . $package->getFilename() . '/src';
            }
        }
        $inventory = [];
        foreach ($paths as $path) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root . '/' . $path, \FilesystemIterator::SKIP_DOTS),
                static fn(\SplFileInfo $entry): bool => !in_array($entry->getFilename(), ['secrets', 'vendor', 'node_modules', '.git'], true),
            ));
            foreach ($iterator as $entry) {
                if ($entry->isFile()) {
                    $relative = substr($entry->getPathname(), strlen($root) + 1);
                    $inventory[$relative] = hash_file('sha256', $entry->getPathname());
                }
            }
        }
        $inventory['composer.lock'] = hash_file('sha256', $root . '/composer.lock');
        ksort($inventory);
        return json_encode($inventory, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) |> (static fn(string $json): string => hash('sha256', $json));
    }

    private static function startIngress(RuntimeWorkflow $web): void
    {
        self::ingress($web, ['mkdir', '-p', '/tmp/baander-capacity-tls']);
        self::ingress($web, [
            'openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
            '-keyout', '/tmp/baander-capacity-tls/key.pem',
            '-out', '/tmp/baander-capacity-tls/cert.pem', '-days', '1',
            '-subj', '/CN=127.0.0.1', '-addext', 'subjectAltName=IP:127.0.0.1',
        ]);
        self::ingress($web, ['chmod', '600', '/tmp/baander-capacity-tls/key.pem']);
        self::ingress($web, ['php', '-r', 'if (file_put_contents("/tmp/baander-capacity-tls/nginx.conf", stream_get_contents(STDIN)) === false) { exit(1); }'], self::ingressConfiguration());
        self::ingress($web, ['nginx', '-c', '/tmp/baander-capacity-tls/nginx.conf']);
        $web->attempts(fn(): bool => self::ingress($web, ['curl', '-kfsS', '-o', '/dev/null', 'https://127.0.0.1:9443/live'], check: false)->exitCode === 0, 20, .25);
        self::ingress($web, ['curl', '-kfsS', '-o', '/dev/null', 'https://127.0.0.1:9443/live']);
    }

    /** @param non-empty-list<string> $arguments */
    private static function ingress(RuntimeWorkflow $web, array $arguments, ?string $input = null, bool $check = true): ProcessResult
    {
        return $web->docker(['exec', '-i', $web->id . '-ingress', ...$arguments], input: $input, check: $check);
    }

    public static function ingressConfiguration(): string
    {
        return <<<'NGINX'
pid /tmp/baander-capacity-tls/nginx.pid;
error_log /dev/null;
events {}
http {
    access_log off;
    client_body_temp_path /tmp/baander-capacity-tls/client-body;
    proxy_temp_path /tmp/baander-capacity-tls/proxy;
    fastcgi_temp_path /tmp/baander-capacity-tls/fastcgi;
    uwsgi_temp_path /tmp/baander-capacity-tls/uwsgi;
    scgi_temp_path /tmp/baander-capacity-tls/scgi;
    map $http_upgrade $connection_upgrade { default upgrade; '' close; }
    server {
        listen 9443 ssl;
        ssl_certificate /tmp/baander-capacity-tls/cert.pem;
        ssl_certificate_key /tmp/baander-capacity-tls/key.pem;
        location / {
            proxy_pass http://app:9501;
            proxy_http_version 1.1;
            proxy_set_header Host $http_host;
            proxy_set_header X-Forwarded-Host $http_host;
            proxy_set_header X-Forwarded-Proto https;
            proxy_set_header X-Forwarded-For $remote_addr;
            proxy_set_header Upgrade $http_upgrade;
            proxy_set_header Connection $connection_upgrade;
            proxy_buffering off;
            proxy_cache off;
            proxy_read_timeout 1800s;
            proxy_send_timeout 1800s;
        }
    }
}
NGINX;
    }

    /** @return array<string,mixed> */
    public static function readManifest(string $path): array
    {
        Runtime::require(str_starts_with($path, '/'), 'Capacity manifest path must be absolute.');
        PrivateDirectory::assertNoSymlinks($path);
        $stat = @lstat($path);
        $owner = stat('/proc/self');
        Runtime::require(is_array($stat) && is_array($owner) && is_file($path) && ($stat['mode'] & 0077) === 0 && $stat['uid'] === $owner['uid'], 'Capacity manifest must be a private file owned by the current user.');
        $directory = stat(dirname($path));
        Runtime::require(($directory['mode'] & 0077) === 0 && $directory['uid'] === $owner['uid'], 'Capacity manifest directory must be private and owned by the current user.');
        $manifest = AtomicFile::readJson($path);
        $keys = ['version', 'containerId', 'imageId', 'ledger', 'appDirectory', 'baseUrl', 'identityPath'];
        Runtime::require(count($manifest) === count($keys) && array_diff(array_keys($manifest), $keys) === [] && $manifest['version'] === 1, 'Invalid capacity manifest contract.');
        foreach (['containerId' => '/\A[a-f0-9]{64}\z/D', 'imageId' => '/\Asha256:[a-f0-9]{64}\z/D', 'baseUrl' => '/\Ahttps:\/\/127\.0\.0\.1:[1-9][0-9]{0,4}\z/D'] as $key => $pattern) {
            Runtime::require(is_string($manifest[$key]) && preg_match($pattern, $manifest[$key]) === 1, 'Invalid capacity manifest field: ' . $key);
        }
        foreach (['ledger', 'appDirectory', 'identityPath'] as $key) {
            Runtime::require(is_string($manifest[$key]) && str_starts_with($manifest[$key], '/') && !str_contains($manifest[$key], "\0"), 'Invalid capacity manifest path: ' . $key);
        }
        Runtime::require($manifest['ledger'] === dirname($path) . '/resources.json' && $manifest['identityPath'] === dirname($path) . '/capacity-runtime-identity.json', 'Capacity artifacts must belong to the manifest directory.');
        return $manifest;
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $ledger
     * @param array<string,mixed> $inspection
     * @return non-empty-list<string>
     */
    public static function fixtureCommand(array $manifest, array $ledger, array $inspection, Target $target, ApplicationLayout $layout): array
    {
        $savedTarget = Target::fromArray($ledger['target'] ?? []);
        $savedTarget->assertSnapshot();
        Runtime::require(($ledger['version'] ?? null) === 1 && self::targetIdentity($savedTarget) === self::targetIdentity($target), 'Capacity fixture executor selected a different Docker target.');
        Runtime::require(($manifest['appDirectory'] ?? null) === $layout->root, 'Capacity fixture directory differs from the selected image layout.');
        $owned = array_any($ledger['resources'] ?? [], static fn(array $resource): bool => ($resource['kind'] ?? null) === 'container' && ($resource['id'] ?? null) === $manifest['containerId']);
        Runtime::require($owned && ($inspection['Id'] ?? null) === $manifest['containerId'] && ($inspection['Image'] ?? null) === $manifest['imageId'], 'Capacity container is absent or has changed identity.');
        Runtime::require(is_string($ledger['token'] ?? null) && ($inspection['Config']['Labels']['org.baander.tooling.run'] ?? null) === $ledger['token'], 'Capacity fixture container has different ownership.');
        Runtime::require(($inspection['State']['Running'] ?? false) === true, 'Capacity fixture container is not running.');
        return [
            'exec', '-i', '-e', 'BAANDER_LIVE_CAPACITY_API_ORIGIN=http://127.0.0.1:9501',
            '-w', $layout->root, $manifest['containerId'],
            'php', 'tests/Fixtures/LiveUpdates/capacity-fixture.php',
        ];
    }

    /** @return array<string,mixed> */
    private static function targetIdentity(Target $target): array
    {
        $files = [];
        foreach ($target->files as $path => $digest) {
            $files[basename($path)] = $digest;
        }
        ksort($files);
        return [
            'executable' => $target->executable,
            'endpoint' => $target->endpoint,
            'daemonId' => $target->daemonId,
            'originalContext' => $target->originalContext,
            'transportArguments' => array_map(static fn(string $argument): string => isset($target->files[$argument]) ? basename($argument) : $argument, $target->transportArguments),
            'files' => $files,
        ];
    }

    public static function executeFixture(string $root, string $manifestPath): int
    {
        $manifest = self::readManifest($manifestPath);
        $runtime = new Runtime($root, budget: 'liveCapacity');
        try {
            $docker = $runtime->docker();
            $docker->validateIdentity();
            PrivateDirectory::assertNoSymlinks($manifest['ledger']);
            $ledger = AtomicFile::readJson($manifest['ledger']);
            $inspection = json_decode($docker->run(['inspect', $manifest['containerId']])->stdout, true, flags: JSON_THROW_ON_ERROR)[0];
            Runtime::require($runtime->image('application')->id === $manifest['imageId'], 'The fixture executor selected a different application image.');
            $command = self::fixtureCommand($manifest, $ledger, $inspection, $docker->target, $runtime->layout());
            return $docker->run($command, interactive: true)->exitCode;
        } finally {
            $runtime->close();
        }
    }
}
