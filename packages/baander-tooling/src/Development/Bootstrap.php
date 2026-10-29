<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Process\{CommandExecutor, ProcessSpec, CompletionPolicy};
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Filesystem\PrivateDirectory;

class Bootstrap
{
    private readonly Connections $connections;

    public function __construct(
        private readonly StateStore $store,
        private readonly CommandExecutor $runner,
        ?Connections $connections = null,
    ) {
        $this->connections = $connections ?? new Connections($runner, Environment::clean());
    }

    /** @param array<string,mixed> $instance
     * @param array<string,string>|null $environment
     * @param array<string,mixed>|null $adoption
     * @return array<string,mixed>
     */
    public function configure(array $instance, ?array $environment = null, ?array $adoption = null): array
    {
        $directory = $this->store->directory($instance['id']);
        if (file_exists($directory . '/env.json')) {
            $saved = new Runtime($this->store, $this->runner, $this->connections)->environment($instance);
            if ($environment !== null && $saved != $environment) {
                throw new WorkflowError('Saved instance identity differs; reconcile configuration explicitly.');
            }
            return $instance;
        }
        if ($adoption !== null) {
            if (empty($environment)) {
                throw new WorkflowError('Adoption requires explicit existing environment and container inventory.');
            }
            $instance = $this->inventory($instance, $adoption, $environment);
            $values = $environment;
        } else {
            $values = array_replace([
                'APP_ENV' => ($instance['kind'] ?? null) === 'persistent' ? 'prod' : 'dev',
                'APP_DEBUG' => '0',
                'APP_SECRET' => bin2hex(random_bytes(32)),
                'DB_HOST' => 'database',
                'DB_PORT' => '5432',
                'DB_USERNAME' => 'baander',
                'DB_DATABASE' => 'baander',
                'DB_PASSWORD' => bin2hex(random_bytes(32)),
                'REDIS_PASSWORD' => bin2hex(random_bytes(32)),
                'STREAM_URL_HMAC_SECRET' => bin2hex(random_bytes(32)),
                'AUTH_SPA_CLIENT_ID' => 'baander_dev_spa_00001',
            ], $environment ?? [], $this->vapid());
            $values['DATABASE_URL'] = 'postgresql://' . rawurlencode($values['DB_USERNAME'])
                . ':' . rawurlencode($values['DB_PASSWORD'])
                . '@database:5432/' . rawurlencode($values['DB_DATABASE'])
                . '?serverVersion=18&charset=utf8';
            $values['MEDIA_STORAGE_PATH'] ??= '/storage/media';
            $values['CONVERT_STORAGE_PATH'] ??= '/storage/conversions';
            $values['REDIS_URL'] = 'redis://default:' . $values['REDIS_PASSWORD'] . '@redis:6379';
            $values['MESSENGER_TRANSPORT_DSN'] = $values['REDIS_URL'] . '/messages';
        }
        PrivateDirectory::prepare($directory);
        AtomicFile::writeJson($directory . '/env.json', $values);
        return $instance;
    }

    /** @return array<string,string> */
    public function vapid(): array
    {
        $directory = sys_get_temp_dir() . '/baander-vapid-' . bin2hex(random_bytes(12));
        PrivateDirectory::prepare($directory);
        $key = $directory . '/private.pem';
        try {
            $this->runner->run(new ProcessSpec([
                'openssl',
                'genpkey',
                '-algorithm',
                'EC',
                '-pkeyopt',
                'ec_paramgen_curve:P-256',
                '-out',
                $key,
            ], processGroup: true));
            chmod($key, 0600);
            $output = $this->runner->run(new ProcessSpec(['openssl', 'ec', '-in', $key, '-text', '-noout'], processGroup: true))->stdout;
            if (preg_match('/priv:(.*?)pub:(.*?)ASN1 OID:/s', $output, $parts) !== 1) {
                throw new WorkflowError('Cannot generate the instance VAPID identity.');
            }
            $private = hex2bin(preg_replace('/[\s:]/', '', $parts[1]));
            $public = hex2bin(preg_replace('/[\s:]/', '', $parts[2]));
            if (
                $private === false
                || $public === false
                || strlen($private) !== 32
                || strlen($public) !== 65
            ) {
                throw new WorkflowError('Cannot generate the instance VAPID identity.');
            }
            $encode = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
            return [
                'VAPID_PRIVATE_KEY' => $encode($private),
                'VAPID_PUBLIC_KEY' => $encode($public),
            ];
        } finally {
            if (file_exists($key)) {
                unlink($key);
            }
            rmdir($directory);
        }
    }

    /** @param array<string,mixed> $instance
     * @param array<string,mixed> $adoption
     * @param array<string,string> $environment
     * @return array<string,mixed>
     */
    public function inventory(array $instance, array $adoption, array $environment): array
    {
        $docker = $this->connections->client($instance['docker']);
        $docker->validateIdentity();
        $containers = $adoption['containers'] ?? [];
        if (
            array_diff(['postgres', 'redis', 'app'], array_keys($containers)) !== []
            || array_diff(array_keys($containers), ['postgres', 'redis', 'app', 'nginx']) !== []
            || array_any($containers, static fn(mixed $value): bool => !$value)
        ) {
            throw new WorkflowError('Select exact postgres, redis and app container IDs for adoption.');
        }
        $inspections = [];
        foreach ($containers as $role => $identifier) {
            $inspected = json_decode($docker->run(['inspect', $identifier])->stdout, true, flags: JSON_THROW_ON_ERROR)[0];
            if ($inspected['Id'] !== $identifier) {
                throw new WorkflowError('Adoption requires immutable full container IDs.');
            }
            $inspections[$role] = $inspected;
        }
        $db = $inspections['postgres'];
        $dbEnv = [];
        foreach ($db['Config']['Env'] as $entry) {
            if (str_contains($entry, '=')) {
                [$key, $value] = explode('=', $entry, 2);
                $dbEnv[$key] = $value;
            }
        }
        $pgdata = $dbEnv['PGDATA'] ?? '/var/lib/postgresql/data';
        $databaseMounts = $db['Mounts']
            |> (static fn(array $mounts): array => array_filter(
                $mounts,
                static fn(array $mount): bool => self::covers($pgdata, $mount['Destination']),
            ))
            |> array_values(...);
        if (count($databaseMounts) !== 1 || $databaseMounts[0]['Type'] !== 'volume') {
            throw new WorkflowError('The selected database must have one recorded existing PGDATA volume.');
        }
        $dbMount = $databaseMounts[0];
        $docker->run(['volume', 'inspect', $dbMount['Name']]);
        foreach ([
            'APP_SECRET',
            'STREAM_URL_HMAC_SECRET',
            'VAPID_PRIVATE_KEY',
            'VAPID_PUBLIC_KEY',
            'AUTH_SPA_CLIENT_ID',
            'DB_PASSWORD',
        ] as $key) {
            if (empty($environment[$key])) {
                throw new WorkflowError('Adoption requires all existing identity secrets and OAuth configuration.');
            }
        }
        foreach ([
            'POSTGRES_PASSWORD' => 'DB_PASSWORD',
            'POSTGRES_USER' => 'DB_USERNAME',
            'POSTGRES_DB' => 'DB_DATABASE',
        ] as $variable => $key) {
            if (($dbEnv[$variable] ?? null) !== ($environment[$key] ?? null)) {
                throw new WorkflowError('Selected PostgreSQL identity differs from the adoption configuration.');
            }
        }
        $layout = $this->imageLayout($docker, $inspections['app']);
        $this->verifyApplicationIdentity($docker, $inspections['app'], $environment);
        $storage = $layout->path('storage');
        foreach ([
            'MEDIA_STORAGE_PATH' => $storage . '/media',
            'CONVERT_STORAGE_PATH' => $storage . '/conversions',
        ] as $key => $default) {
            $target = \Baander\Tooling\Filesystem\Paths::absolutePath($environment[$key] ?? $default);
            if (self::covers($target, $storage) || self::covers($storage, $target)) {
                throw new WorkflowError('Unsupported adoption topology: ' . $key . ' overlaps writable storage at ' . $storage . '. Preserve the original deployment and prepare distinct source, conversion and storage paths before adoption.');
            }
        }
        $targets = [
            'var' => $layout->path('var'),
            'secrets' => $layout->path('config/secrets'),
            'storage' => $storage,
            'media' => $environment['MEDIA_STORAGE_PATH'] ?? $storage . '/media',
            'conversions' => $environment['CONVERT_STORAGE_PATH'] ?? $storage . '/conversions',
            'redis' => '/data',
        ];
        if (array_any(
            $inspections['app']['Mounts'],
            static fn(array $mount): bool => $mount['Destination'] === $layout->path('var/transcode_state'),
        )) {
            $targets['transcode_state'] = $layout->path('var/transcode_state');
        }
        $stateMounts = [];
        $volumes = array_replace($instance['volumes'] ?? [], ['database' => $dbMount['Name']]);
        foreach ($targets as $key => $target) {
            $role = $key === 'redis' ? 'redis' : 'app';
            $matches = $inspections[$role]['Mounts']
                |> (static fn(array $mounts): array => array_filter(
                    $mounts,
                    static fn(array $mount): bool => self::covers($target, $mount['Destination']),
                ))
                |> array_values(...);
            usort($matches, static fn(array $a, array $b): int => strlen($b['Destination']) <=> strlen($a['Destination']));
            if ($matches === [] || !in_array($matches[0]['Type'], ['volume', 'bind'], true)) {
                throw new WorkflowError('Adoption inventory lacks an exact writable mount for ' . $target);
            }
            $mount = $matches[0];
            $suffix = ltrim(substr($target, strlen($mount['Destination'])), '/');
            if ($suffix !== '' && $mount['Type'] === 'volume') {
                throw new WorkflowError('Nested writable volume adoption requires an explicit copy after quiescence.');
            }
            if ($mount['Type'] === 'volume') {
                $docker->run(['volume', 'inspect', $mount['Name']]);
                $volumes[$key] = $mount['Name'];
            }
            $stateMounts[$key] = [
                'type' => $mount['Type'],
                'source' => $mount['Type'] === 'volume'
                    ? $mount['Name']
                    : rtrim($mount['Source'], '/') . ($suffix === '' ? '' : '/' . $suffix),
                'target' => $target,
            ];
            if ($key === 'media') {
                $stateMounts[$key]['read_only'] = true;
            }
        }
        $media = [];
        foreach ($inspections['app']['Mounts'] as $mount) {
            if (
                $mount['Type'] === 'bind'
                && !in_array($mount['Destination'], $targets, true)
                && !self::covers($mount['Destination'], $layout->root)
            ) {
                $media[] = [
                    'source' => $mount['Source'],
                    'target' => $mount['Destination'],
                ];
            }
        }
        if (
            !isset($adoption['worker_manifests'])
            || !is_array($adoption['worker_manifests'])
            || !array_is_list($adoption['worker_manifests'])
        ) {
            throw new WorkflowError('Record the current worker manifests and owner before adopting writable resources.');
        }
        $keyCheck = <<<'CODE'
$base=$argv[3];
$a=str_replace('%kernel.project_dir%',$base,$argv[1]);
$b=str_replace('%kernel.project_dir%',$base,$argv[2]);
if (!is_file($a)||!is_file($b)||is_link($a)||is_link($b)) { exit(2); }
$p=openssl_pkey_get_private(file_get_contents($a));
$q=openssl_pkey_get_public(file_get_contents($b));
if ($p===false||$q===false||!openssl_sign('adoption',$s,$p,OPENSSL_ALGO_SHA256)||openssl_verify('adoption',$s,$q,OPENSSL_ALGO_SHA256)!==1) { exit(3); }
CODE;
        $docker->run([
            'exec',
            $containers['app'],
            'php',
            '-r',
            $keyCheck,
            $environment['OAUTH_PRIVATE_KEY_PATH'] ?? $layout->path('config/secrets/oauth/private.key'),
            $environment['OAUTH_PUBLIC_KEY_PATH'] ?? $layout->path('config/secrets/oauth/public.key'),
            $layout->root,
        ]);
        return array_replace($instance, [
            'adoption' => $adoption,
            'adoption_inventory' => $inspections,
            'volumes' => $volumes,
            'pgdata' => $pgdata,
            'postgres_mount_target' => $dbMount['Destination'],
            'postgres_image' => $db['Image'],
            'redis_image' => $inspections['redis']['Image'],
            'state_mounts' => $stateMounts,
            'media' => $media,
            'initialization' => 'adopted',
            'warnings' => ['An origin change requires login again; passkeys remain bound to their enrolled relying party.'],
        ]);
    }

    /** @param array<string,mixed> $app */
    private function imageLayout(\Baander\Tooling\Docker\Client $docker, array $app): ApplicationLayout
    {
        $images = json_decode($docker->run(['image', 'inspect', $app['Image']])->stdout, true, flags: JSON_THROW_ON_ERROR);
        $image = $images[0] ?? null;
        if (!is_array($image) || ($image['Id'] ?? null) !== $app['Image']) {
            throw new WorkflowError('Selected application image identity could not be verified; preserve the original deployment.');
        }
        return ApplicationLayout::fromImage($image);
    }

    private static function covers(string $target, string $parent): bool
    {
        return $target === $parent || str_starts_with($target, rtrim($parent, '/') . '/');
    }

    /** @param array<string,mixed> $app
     * @param array<string,string> $environment
     */
    public function verifyApplicationIdentity(\Baander\Tooling\Docker\Client $docker, array $app, array $environment): void
    {
        $layout = $this->imageLayout($docker, $app);
        $keys = ['APP_SECRET', 'STREAM_URL_HMAC_SECRET', 'VAPID_PRIVATE_KEY', 'VAPID_PUBLIC_KEY'];
        $existing = [];
        foreach ($app['Config']['Env'] ?? [] as $entry) {
            if (!str_contains($entry, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $entry, 2);
            if (in_array($key, $keys, true)) {
                if (isset($existing[$key])) {
                    throw new WorkflowError('Cannot verify duplicate application identity configuration; original services remain unchanged.');
                }
                $existing[$key] = hash('sha256', $value);
            }
        }
        $missing = array_diff($keys, array_keys($existing))
            |> array_values(...);
        if ($missing !== []) {
            $probe = <<<'CODE'
$base=$argv[2];
require $base.'/vendor/autoload.php';
(new \Symfony\Component\Dotenv\Dotenv())->bootEnv($base.'/.env');
$result=[];
foreach (json_decode($argv[1],true,512,JSON_THROW_ON_ERROR) as $key) {
    $value=$_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
    $result[$key]=is_string($value) && $value!=='' ? hash('sha256',$value) : null;
}
echo json_encode($result,JSON_THROW_ON_ERROR);
CODE;
            try {
                $response = $docker->run([
                    'exec',
                    $app['Id'],
                    'php',
                    '-d',
                    'display_errors=0',
                    '-r',
                    $probe,
                    json_encode($missing, JSON_THROW_ON_ERROR),
                    $layout->root,
                ], timeout: 30);
                $digests = json_decode($response->stdout, true, flags: JSON_THROW_ON_ERROR);
                if (
                    !is_array($digests)
                    || count($digests) !== count($missing)
                    || array_diff(array_keys($digests), $missing) !== []
                ) {
                    throw new \RuntimeException('Incomplete observation');
                }
                $existing = array_replace($existing, $digests);
            } catch (\Throwable) {
                throw new WorkflowError('Cannot verify existing application identity; preserve the original services and reconcile configuration.');
            }
        }
        foreach ($keys as $key) {
            $supplied = $environment[$key] ?? null;
            $digest = $existing[$key] ?? null;
            if (
                !is_string($supplied)
                || $supplied === ''
                || !is_string($digest)
                || strlen($digest) !== 64
            ) {
                throw new WorkflowError('Cannot verify existing application identity; preserve the original services and reconcile configuration.');
            }
            if (!hash_equals(hash('sha256', $supplied), $digest)) {
                throw new WorkflowError('Selected application identity differs from the adoption configuration; original services remain unchanged.');
            }
        }
    }

    /** @param array<string,mixed> $instance
     * @param array<string,mixed> $release
     * @param array<string,mixed> $checkpoint
     * @return array<string,mixed>
     */
    public function transfer(array $instance, array $release, array $checkpoint): array
    {
        Database::quiescent($instance);
        if (($checkpoint['verified'] ?? null) !== true) {
            throw new WorkflowError('Adoption transfer requires a verified checkpoint and isolated migration rehearsal.');
        }
        foreach ($instance['adoption_transfer'] ?? [] as $transfer) {
            if (!is_array($transfer) || !in_array($transfer['phase'] ?? null, ['planned', 'complete'], true)) {
                throw new WorkflowError('Adoption extraction was interrupted; preserve the partial volume, original data and checkpoint for explicit reconciliation.');
            }
        }
        if (!empty($instance['ownership_transferred'])) {
            return $instance;
        }
        $docker = $this->connections->client($instance['docker']);
        $docker->validateIdentity();
        $inventory = $instance['adoption_inventory'];
        foreach ($inventory as $role => $recorded) {
            $actual = json_decode($docker->run(['inspect', $recorded['Id']])->stdout, true, flags: JSON_THROW_ON_ERROR)[0];
            if (
                $actual['Id'] !== $recorded['Id']
                || $actual['Image'] !== $recorded['Image']
                || $actual['Mounts'] != $recorded['Mounts']
            ) {
                throw new WorkflowError('Adoption container identity or mounts changed; preserve the checkpoint and reconcile inventory.');
            }
            if (in_array($role, ['app', 'nginx'], true) && $actual['State']['Running']) {
                throw new WorkflowError('The original app writer and recorded frontend must be stopped before adoption handoff.');
            }
        }
        foreach (['postgres', 'redis'] as $role) {
            $docker->run(['stop', '--time', '60', $inventory[$role]['Id']], timeout: 90);
            $actual = json_decode($docker->run(['inspect', $inventory[$role]['Id']])->stdout, true, flags: JSON_THROW_ON_ERROR)[0];
            if ($actual['State']['Running']) {
                throw new WorkflowError('Original data service did not stop; no replacement may start.');
            }
        }
        $mounts = $instance['state_mounts'];
        $volumes = $instance['volumes'];
        foreach ($mounts as $key => $mount) {
            if ($mount['type'] !== 'bind' || !empty($mount['read_only'])) {
                continue;
            }
            $destination = $instance['project'] . '-adopted-' . $key . '-' . substr(basename($checkpoint['directory']), 0, 12);
            $this->transferVolume($instance, $release, $docker, $key, $destination, $checkpoint['directory'] . '/' . $key . '.tar');
            $mounts[$key] = array_replace($mount, ['type' => 'volume', 'source' => $destination]);
            $volumes[$key] = $destination;
        }
        if (!isset($mounts['transcode_state'])) {
            $transcode = $instance['project'] . '-adopted-transcode-' . substr(basename($checkpoint['directory']), 0, 12);
            $this->transferVolume($instance, $release, $docker, 'transcode_state', $transcode, $checkpoint['directory'] . '/transcode_state.tar');
            $volumes['transcode_state'] = $transcode;
            $mounts['transcode_state'] = [
                'type' => 'volume',
                'source' => $transcode,
                'target' => ApplicationLayout::fromArray($release['layout'])->path('var/transcode_state'),
            ];
        }
        $transferred = array_replace($instance, [
            'state_mounts' => $mounts,
            'volumes' => $volumes,
            'ownership_transferred' => true,
        ]);
        $this->store->save($instance['id'], $transferred);
        return $transferred;
    }

    /** Persist data-volume ownership before creation; an incomplete extraction is never reset automatically.
     * @param array<string,mixed> $instance
     * @param array<string,mixed> $release
     */
    private function transferVolume(array &$instance, array $release, \Baander\Tooling\Docker\Client $docker, string $key, string $destination, string $archive): void
    {
        $saved = $instance['adoption_transfer'][$key] ?? null;
        if ($saved !== null) {
            if (($saved['volume'] ?? null) !== $destination || ($saved['archive'] ?? null) !== $archive) {
                throw new WorkflowError('Saved adoption transfer identity changed; preserve the checkpoint and original data.');
            }
            if (($saved['phase'] ?? null) === 'complete') {
                return;
            }
            if (($saved['phase'] ?? null) === 'copying') {
                throw new WorkflowError('Adoption extraction was interrupted; preserve the partial volume, original data and checkpoint for explicit reconciliation.');
            }
        } else {
            $instance['adoption_transfer'][$key] = ['volume' => $destination, 'archive' => $archive, 'phase' => 'planned'];
            $this->store->save($instance['id'], $instance);
        }
        $owner = $instance['id'];
        $docker->run(['volume', 'create', '--label', 'app.baander.development.owner=' . $owner, $destination]);
        $inspection = json_decode($docker->run(['volume', 'inspect', $destination])->stdout, true, flags: JSON_THROW_ON_ERROR)[0] ?? null;
        if (!is_array($inspection) || ($inspection['Name'] ?? null) !== $destination
            || ($inspection['Labels']['app.baander.development.owner'] ?? null) !== $owner) {
            throw new WorkflowError('Adoption destination volume is not owned by this instance; preserve existing data and the checkpoint.');
        }
        $instance['adoption_transfer'][$key]['phase'] = 'copying';
        $this->store->save($instance['id'], $instance);
        $this->restoreArchive($docker, $release, $destination, $archive);
        $instance['adoption_transfer'][$key]['phase'] = 'complete';
        $this->store->save($instance['id'], $instance);
    }

    /** @param array<string,mixed> $release */
    public function restoreArchive(\Baander\Tooling\Docker\Client $docker, array $release, string $destination, string $archive): void
    {
        $helper = 'baander-adoption-copy-' . bin2hex(random_bytes(8));
        $receipt = dirname($archive) . '/restore-' . hash('sha256', $destination) . '-resources.json';
        if (file_exists($receipt) || is_link($receipt)) {
            throw new WorkflowError('Recover the unresolved adoption helper before retrying: ' . $receipt);
        }
        $ledger = new \Baander\Tooling\Resources\ResourceLedger($receipt, $docker, $this->connections->environment());
        $ledger->scoped(function () use ($ledger, $docker, $helper, $release, $destination, $archive): void {
            $ledger->create(\Baander\Tooling\Resources\ResourceKind::Container, $helper, [
                '--network',
                'none',
                '--user',
                '0:0',
                '--entrypoint',
                '/bin/sh',
                '--mount',
                'type=volume,src=' . $destination . ',dst=/restore',
                $release['images']['app_image']['resolvedId'],
                '-ec',
                'test -z "$(ls -A /restore)"; tar -xpf /tmp/checkpoint.tar -C /restore',
            ]);
            $docker->run(['cp', $archive, $helper . ':/tmp/checkpoint.tar'], timeout: null);
            $docker->run(['start', '--attach', $helper], timeout: null);
            if (trim($docker->run(['inspect', '--format', '{{.State.ExitCode}}', $helper])->stdout) !== '0') {
                throw new WorkflowError('Adoption archive extraction failed; retain the original resources and checkpoint for recovery.');
            }
        }, $release['effective']['budgets']['cleanup'] ?? 120.0);

    }

    /** @param array<string,mixed> $instance
     * @param array<string,mixed> $release
     * @return array<string,mixed>
     */
    public function initialize(array $instance, array $release): array
    {
        $database = new Database($this->store, $this->runner, $this->connections);
        $catalog = $database->inspect($instance, $release);
        if (($instance['initialization'] ?? null) === 'adopted') {
            if ($catalog['fresh']) {
                throw new WorkflowError('Adoption requires a compatible populated database.');
            }
            $clientId = str_replace(
                "'",
                "''",
                new Runtime($this->store, $this->runner, $this->connections)->environment($instance)['AUTH_SPA_CLIENT_ID'],
            );
            $client = $database->sql(
                $instance,
                "BEGIN READ ONLY; SELECT json_build_object('valid',EXISTS(SELECT 1 FROM oauth_clients WHERE public_id='"
                . $clientId
                . "' AND first_party AND password_client AND NOT revoked)); COMMIT;",
            );
            if (empty($client['valid'])) {
                throw new WorkflowError('The adopted first-party OAuth client is missing or unsuitable; preserve identities and reconcile configuration.');
            }
            return $instance;
        }
        if (($instance['initialization'] ?? null) === 'complete') {
            return $instance;
        }
        if (!$catalog['fresh'] && ($instance['initialization'] ?? null) !== 'initializing') {
            throw new WorkflowError('A populated database requires explicit adoption; development users will not be seeded.');
        }
        $instance = array_replace($instance, [
            'initialization' => 'initializing',
            'phase' => 'database_mutating',
        ]);
        $this->store->save($instance['id'], $instance);
        new Runtime($this->store, $this->runner, $this->connections)->prepare($instance, $release);
        $docker = $this->connections->client($instance['docker']);
        $docker->validateIdentity();
        $instance['database_mutation_started'] = true;
        $this->store->save($instance['id'], $instance);
        try {
            $application = new Runtime($this->store, $this->runner, $this->connections)->applicationContainer($instance, $release, [
                'php', 'bin/console', 'app:dev:setup', '--origin=https://' . $instance['hostname'], '--no-interaction',
            ]);
            \Baander\Tooling\Resources\DisposableContainer::run(
                $docker,
                $this->store->directory($instance['id']) . '/initialize-resources.json',
                $application['arguments'],
                $this->connections->environment(),
                $release['effective']['budgets']['cleanup'] ?? 120.0,
                containerEnvironment: $application['environment'],
            );
        } catch (\Throwable $error) {
            $this->store->save($instance['id'], array_replace($instance, [
                'phase' => 'blocked',
                'failure' => 'Fresh initialization interrupted; resume this identity without regenerating secrets.',
            ]));
            throw $error;
        }
        $catalog = $database->inspect($instance, $release);
        if (!empty($catalog['pending'])) {
            throw new WorkflowError('Fresh initialization did not finish all target migrations.');
        }
        $instance = array_replace($instance, [
            'initialization' => 'complete',
            'migration_hashes' => $catalog['migration_hashes'],
        ]);
        $this->store->save($instance['id'], $instance);
        return $instance;
    }
}
