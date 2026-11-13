<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Qualification;

use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Deployment\PresetConfiguration;
use Baander\Tooling\Docker\Client;
use RuntimeException;

final class Application
{
    private readonly PresetConfiguration $configuration;
    private readonly ApplicationLayout $layout;
    /** @var array<string, string> */
    private array $containers = [];
    /** @var array<string, array<string, mixed>> */
    private array $librariesById = [];

    /**
     * @param array<string, mixed> $deploymentState
     * @param array<string, array<string,mixed>> $provisionedLibraries
     */
    public function __construct(private readonly Client $docker, private readonly array $deploymentState, array $provisionedLibraries = [])
    {
        if (($deploymentState['version'] ?? null) !== 1 || ($deploymentState['phase'] ?? null) !== 'running'
            || !is_string($deploymentState['owner'] ?? null) || $deploymentState['owner'] === ''
            || !is_string($deploymentState['composeFile'] ?? null) || !str_starts_with($deploymentState['composeFile'], '/')
            || !is_array($deploymentState['configuration'] ?? null) || !is_array($deploymentState['layout'] ?? null)
            || ($deploymentState['target'] ?? null) != $docker->target->toArray()) {
            throw new RuntimeException('Application requires the running deployment state on its captured Docker target');
        }
        $this->configuration = PresetConfiguration::fromArray($deploymentState['configuration']);
        $this->layout = ApplicationLayout::fromArray($deploymentState['layout']);
        foreach ($provisionedLibraries as $role => $library) {
            self::uuid($library['id'] ?? null);
            self::publicId($library['publicId'] ?? null);
            $type = $role === 'music' ? 'music' : 'movie';
            if (!in_array($role, ['music', 'movies', 'episodes'], true) || ($library['type'] ?? null) !== $type
                || !is_string($library['path'] ?? null) || !preg_match('~^/storage/media/[a-z0-9][a-z0-9-]{0,47}/' . $role . '$~D', $library['path'])) {
                throw new RuntimeException('Invalid saved fixture library receipt');
            }
            $this->librariesById[$library['id']] = $library;
        }
    }

    /**
     * @param array<string, mixed> $dataset
     * @param array<array<string,mixed>> $users
     * @return array<string, mixed>
     */
    public function provision(array $dataset, #[\SensitiveParameter] array $users): array
    {
        $run = self::datasetRun($dataset);
        if (count($users) !== 6 || !array_is_list($users)) {
            throw new RuntimeException('Provision six distinct fixture accounts, two for each overlap scenario');
        }
        $emails = [];
        foreach ($users as $user) {
            if (!is_string($user['email'] ?? null) || filter_var($user['email'], FILTER_VALIDATE_EMAIL) === false
                || !preg_match('/@(?:[a-z0-9-]+\.)*baander\.app$/iD', $user['email'])
                || !is_string($user['name'] ?? null) || trim($user['name']) === ''
                || !is_string($user['password'] ?? null) || mb_strlen($user['password']) < 8 || mb_strlen($user['password']) > 255
                || preg_match('/[\x00-\x1f\x7f]/', $user['password']) || trim($user['password']) !== $user['password']) {
                throw new RuntimeException('Invalid fixture account; use baander.app addresses and valid passwords');
            }
            $emails[] = strtolower($user['email']);
        }
        if (count(array_unique($emails)) !== 6) {
            throw new RuntimeException('Fixture accounts must be distinct across overlap scenarios');
        }
        $this->container('web');
        $oauth = $this->setupClient();
        $createdUsers = [];
        foreach ($users as $user) {
            $created = $this->console(['app:user:create', $user['email'], $user['name'], '--password'], $user['password'] . "\n");
            self::publicId($created['publicId'] ?? null);
            if (($created['email'] ?? null) !== $user['email']) {
                throw new RuntimeException('User creation returned a different fixture account');
            }
            $createdUsers[] = ['publicId' => $created['publicId'], 'email' => $user['email'], 'name' => $user['name']];
        }
        $libraries = [];
        foreach (['music', 'movies', 'episodes'] as $role) {
            $spec = $dataset['libraries'][$role];
            $path = '/storage/media/' . $spec['path'];
            $library = $this->console(['app:library:create', 'Qualification ' . $run . ' ' . $role, $path, $spec['type'], '--slug=qualification-' . $run . '-' . $role]);
            self::publicId($library['publicId'] ?? null);
            if (($library['path'] ?? null) !== $path || ($library['type'] ?? null) !== $spec['type']) {
                throw new RuntimeException('Library creation returned different fixture storage');
            }
            $libraries[$role] = ['publicId' => $library['publicId'], 'path' => $path, 'type' => $spec['type']];
            foreach ($createdUsers as $user) {
                $grant = $this->console(['app:library:member:grant', $user['email'], $library['publicId']]);
                if (($grant['granted'] ?? null) !== true) {
                    throw new RuntimeException('Fixture library access was not granted');
                }
            }
        }
        $ids = implode(', ', array_map(static fn(array $library): string => self::literal($library['publicId']), array_values($libraries)));
        $rows = $this->query("SELECT coalesce(jsonb_agg(jsonb_build_object('id', id, 'publicId', public_id, 'path', path, 'type', type) ORDER BY public_id), '[]'::jsonb) FROM libraries WHERE public_id IN ({$ids})");
        foreach ($libraries as $role => &$library) {
            $matches = array_values(array_filter($rows, static fn(array $row): bool => ($row['publicId'] ?? null) === $library['publicId']));
            if (count($matches) !== 1 || ($matches[0]['path'] ?? null) !== $library['path'] || ($matches[0]['type'] ?? null) !== $library['type']) {
                throw new RuntimeException('Fixture library catalog identity is missing or ambiguous');
            }
            self::uuid($matches[0]['id'] ?? null);
            $library['id'] = $matches[0]['id'];
            $this->librariesById[$library['id']] = $library;
        }
        unset($library);
        return ['libraries' => $libraries, 'users' => $createdUsers, 'oauth' => $oauth];
    }

    /** @param list<string> $libraryUuids
     * @return list<array<array-key,mixed>>
     */
    public function startScan(array $libraryUuids): array
    {
        if ($libraryUuids === [] || count($libraryUuids) > 3 || count(array_unique($libraryUuids)) !== count($libraryUuids)) {
            throw new RuntimeException('Select one to three provisioned fixture libraries for scanning');
        }
        foreach ($libraryUuids as $id) {
            self::uuid($id);
            if (!isset($this->librariesById[$id])) {
                throw new RuntimeException('Scan requested a library not provisioned by this helper');
            }
        }
        $ids = implode(', ', array_map(static fn(string $id): string => self::literal($id) . '::uuid', $libraryUuids));
        $rows = $this->query("SELECT coalesce(jsonb_agg(jsonb_build_object('id', id, 'publicId', public_id, 'path', path, 'type', type)), '[]'::jsonb) FROM libraries WHERE id IN ({$ids})");
        if (count($rows) !== count($libraryUuids)) {
            throw new RuntimeException('Saved fixture library catalog identity is missing');
        }
        foreach ($rows as $row) {
            $saved = $this->librariesById[$row['id'] ?? ''] ?? null;
            if ($saved === null || ($row['publicId'] ?? null) !== $saved['publicId'] || ($row['path'] ?? null) !== $saved['path'] || ($row['type'] ?? null) !== $saved['type']) {
                throw new RuntimeException('Saved fixture library catalog identity changed');
            }
        }
        $receipts = [];
        foreach ($libraryUuids as $id) {
            $receipts[] = $this->console(['app:library:scan', $this->librariesById[$id]['publicId']], timeout: 1800);
        }
        return $receipts;
    }

    /** @return array<array-key, mixed> */
    public function startMetadataSync(): array
    {
        $receipt = $this->console(['app:metadata:sync']);
        if (!is_int($receipt['jobsDispatched'] ?? null) || $receipt['jobsDispatched'] < 0 || !is_string($receipt['jobId'] ?? null) || $receipt['jobId'] === '') {
            throw new RuntimeException('Invalid fixture metadata dispatch receipt');
        }
        return ['scope' => 'all-libraries'] + $receipt;
    }

    /** @param array<string, mixed> $dataset
     * @return array<string, mixed>
     */
    public function playbackSlots(array $dataset): array
    {
        $run = self::datasetRun($dataset);
        $playback = $dataset['playback'] ?? null;
        if (!is_array($playback) || !is_array($playback['originalVideos'] ?? null) || count($playback['originalVideos']) !== 2
            || !is_array($playback['copyVideos'] ?? null) || count($playback['copyVideos']) !== 2) {
            throw new RuntimeException('Missing fixture playback selection');
        }
        $paths = [...$playback['originalVideos'], ...$playback['copyVideos']];
        foreach (['music', 'audioOnlyCopy', 'noAudio', 'irregularKeyframes', 'nonzeroTimestamps', 'unsupportedAudio'] as $key) {
            $paths[] = $playback[$key] ?? null;
        }
        $paths[] = $playback['burnIn']['path'] ?? null;
        foreach ($paths as $path) {
            self::sourcePath($path, $run);
        }
        $paths = array_values(array_unique($paths));
        $files = $dataset['files'] ?? [];
        if (!is_array($files)) {
            throw new RuntimeException('Missing fixture source files');
        }
        $requested = [];
        foreach ($paths as $path) {
            $matches = array_values(array_filter($files, static fn(array $file): bool => ($file['path'] ?? null) === $path));
            if (count($matches) !== 1 || !in_array($matches[0]['kind'] ?? null, ['song', 'movie', 'episode'], true)) {
                throw new RuntimeException('Playback source does not name one fixture media file');
            }
            $kind = $matches[0]['kind'];
            $role = $kind === 'song' ? 'music' : ($kind === 'episode' ? 'episodes' : 'movies');
            $requested[] = ['path' => '/storage/media/' . $path, 'library' => '/storage/media/' . $run . '/' . $role, 'kind' => $kind === 'song' ? 'song' : 'video'];
        }
        $input = self::literal(json_encode($requested, JSON_THROW_ON_ERROR));
        $rows = $this->query(<<<SQL
            WITH requested AS (SELECT * FROM jsonb_to_recordset({$input}::jsonb) AS r(path text, library text, kind text)),
            playback_catalog AS (
                SELECT s.path, s.title, s.public_id AS "songPublicId", a.public_id AS "albumPublicId", NULL::text AS "moviePublicId", NULL::text AS "videoPublicId"
                FROM requested r JOIN songs s ON s.path = r.path JOIN albums a ON a.id = s.album_id JOIN libraries l ON l.id = a.library_id AND l.path = r.library AND l.type = 'music' WHERE r.kind = 'song'
                UNION ALL
                SELECT v.path, m.title, NULL::text, NULL::text, m.public_id, v.public_id
                FROM requested r JOIN videos v ON v.path = r.path JOIN movie_video mv ON mv.video_id = v.id JOIN movies m ON m.id = mv.movie_id JOIN libraries l ON l.id = m.library_id AND l.path = r.library AND l.type = 'movie' WHERE r.kind = 'video'
            ) SELECT coalesce(jsonb_agg(to_jsonb(c)), '[]'::jsonb) FROM (SELECT * FROM playback_catalog ORDER BY path LIMIT 17) c
            SQL);
        if (count($rows) !== count($paths)) {
            throw new RuntimeException('Fixture playback catalog is incomplete or ambiguous');
        }
        $byPath = [];
        foreach ($rows as $row) {
            $path = $row['path'] ?? null;
            if (!is_string($path) || isset($byPath[$path]) || !in_array($path, array_column($requested, 'path'), true) || !is_string($row['title'] ?? null)) {
                throw new RuntimeException('Invalid fixture playback catalog row');
            }
            $song = ($row['songPublicId'] ?? null) !== null;
            foreach ($song ? ['songPublicId', 'albumPublicId'] : ['moviePublicId', 'videoPublicId'] as $key) {
                self::publicId($row[$key] ?? null);
            }
            $row['route'] = $song ? '/albums/' . $row['albumPublicId'] : '/movies/' . $row['moviePublicId'];
            $byPath[$path] = $row;
        }
        $result = [];
        foreach (['originalVideos', 'copyVideos'] as $key) {
            $result[$key] = array_map(static fn(string $path): array => $byPath['/storage/media/' . $path], $playback[$key]);
        }
        foreach (['music', 'audioOnlyCopy', 'noAudio', 'irregularKeyframes', 'nonzeroTimestamps', 'unsupportedAudio'] as $key) {
            $result[$key] = $byPath['/storage/media/' . $playback[$key]];
        }
        $result['burnIn'] = $byPath['/storage/media/' . $playback['burnIn']['path']] + ['subtitleBurnIn' => true];
        return $result;
    }

    /** @return array{clientId:string,type:string} */
    private function setupClient(): array
    {
        $root = var_export($this->layout->root, true);
        $dotenv = var_export($this->layout->path('.env'), true);
        $probe = '<?php $root = ' . $root . '; require $root . "/vendor/autoload.php"; (new Symfony\\Component\\Dotenv\\Dotenv())->bootEnv(' . $dotenv . '); echo json_encode(["clientId" => $_SERVER["AUTH_SPA_CLIENT_ID"] ?? $_ENV["AUTH_SPA_CLIENT_ID"] ?? getenv("AUTH_SPA_CLIENT_ID")], JSON_THROW_ON_ERROR);';
        $effective = $this->json($this->execute(['exec', '-i', $this->container('web'), 'php'], $probe, 15), 'login client configuration');
        if (($effective['clientId'] ?? null) !== 'baander_dev_spa_00001') {
            throw new RuntimeException('Qualification fixture requires the default AUTH_SPA_CLIENT_ID=baander_dev_spa_00001 for app:auth:setup-clients');
        }
        $this->execute(['exec', $this->container('web'), 'php', $this->layout->path('bin/console'), 'app:auth:setup-clients', '--origin=' . $this->configuration->origin, '--no-interaction'], null, 60);
        $rows = $this->query("SELECT coalesce(jsonb_agg(jsonb_build_object('clientId', public_id, 'firstParty', first_party, 'passwordClient', password_client, 'revoked', revoked)), '[]'::jsonb) FROM oauth_clients WHERE public_id = 'baander_dev_spa_00001'");
        if (count($rows) !== 1 || ($rows[0]['firstParty'] ?? null) !== true || ($rows[0]['passwordClient'] ?? null) !== true || ($rows[0]['revoked'] ?? null) !== false) {
            throw new RuntimeException('Fixture login client is missing, revoked or not first-party');
        }
        return ['clientId' => 'baander_dev_spa_00001', 'type' => 'first_party'];
    }

    /** @return array<array-key, mixed> */
    public function queues(): array
    {
        $root = var_export($this->layout->root, true);
        $program = <<<PHP
            <?php
            \$root = {$root};
            require \$root . '/vendor/autoload.php';
            \$class = 'App\\Shared\\Infrastructure\\Worker\\Autoscaling\\RedisQueueSampler';
            \$options = ['group' => 'baander', 'stream' => 'messages'];
            echo json_encode([
                'async' => (new \$class(getenv('MESSENGER_TRANSPORT_DSN'), \$options))->sample(),
                'scheduler' => (new \$class(getenv('REDIS_URL') . '/scheduler_occurrences/baander', ['group' => 'baander', 'stream' => 'scheduler_occurrences']))->sample(),
            ], JSON_THROW_ON_ERROR);
            PHP;
        $observations = $this->json($this->execute(['exec', '-i', $this->container('web'), 'php'], $program, 10), 'queue observation');
        foreach (['async', 'scheduler'] as $transport) {
            $sample = $observations[$transport] ?? null;
            if (!is_array($sample) || !in_array($sample['state'] ?? null, ['known', 'unknown_lag', 'uninitialized', 'unavailable'], true)
                || !is_numeric($sample['sampledAt'] ?? null)) {
                throw new RuntimeException('Invalid fixture queue observation');
            }
            foreach (['unreadCount', 'pendingCount', 'dueCount', 'futureCount'] as $field) {
                $value = $sample[$field] ?? null;
                if (($value !== null && (!is_int($value) || $value < 0)) || ($sample['state'] === 'known' && $value === null)) {
                    throw new RuntimeException('Invalid fixture queue observation count');
                }
            }
        }
        return $observations;
    }

    /** @param list<string> $arguments
     * @return array<array-key,mixed>
     */
    private function console(array $arguments, #[\SensitiveParameter] ?string $input = null, float $timeout = 60): array
    {
        return $this->json($this->execute(['exec', '-i', $this->container('web'), 'php', $this->layout->path('bin/console'), ...$arguments, '--json', '--no-interaction'], $input, $timeout), 'application command');
    }

    /** @return list<array<string,mixed>> */
    private function query(string $sql): array
    {
        $env = $this->configuration->environment;
        $output = $this->execute(['exec', '-i', $this->container('postgres'), 'psql', '-X', '-qAt', '-v', 'ON_ERROR_STOP=1', '-U', $env['DB_USERNAME'], '-d', $env['DB_DATABASE']], "BEGIN READ ONLY; SET LOCAL statement_timeout = '10s'; SET LOCAL lock_timeout = '1s'; SET LOCAL standard_conforming_strings = on;\n" . $sql . ";\nCOMMIT;", 15);
        $rows = $this->json($output, 'catalog lookup');
        if (!array_is_list($rows) || count($rows) > 17) {
            throw new RuntimeException('Invalid or unbounded fixture catalog lookup');
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Invalid fixture catalog row');
            }
        }
        return $rows;
    }

    private function container(string $service): string
    {
        if (isset($this->containers[$service])) {
            return $this->containers[$service];
        }
        $state = $this->deploymentState;
        $id = trim($this->execute(['compose', '--project-name', $this->configuration->name, '--file', $state['composeFile'], 'ps', '--quiet', $service], null, 15));
        if (!preg_match('/^[a-f0-9]{64}$/D', $id)) {
            throw new RuntimeException('Fixture requires one running immutable ' . $service . ' container');
        }
        $inspection = $this->json($this->execute(['inspect', $id], null, 15), 'container inspection');
        $container = $inspection[0] ?? null;
        $image = $state['images'][$service === 'web' ? 'application' : $service]['id'] ?? null;
        if (!is_array($container) || ($container['Id'] ?? null) !== $id || ($container['Image'] ?? null) !== $image
            || ($container['State']['Running'] ?? null) !== true || ($container['Config']['Labels']['org.baander.preset.owner'] ?? null) !== $state['owner']) {
            throw new RuntimeException('Fixture container ownership or image changed');
        }
        if ($service === 'web') {
            $mounts = array_values(array_filter($container['Mounts'] ?? [], static fn(array $mount): bool => ($mount['Destination'] ?? null) === '/storage/media'));
            if (count($mounts) !== 1 || ($mounts[0]['Type'] ?? null) !== 'volume' || ($mounts[0]['Name'] ?? null) !== $this->configuration->volumes['media'] || ($mounts[0]['RW'] ?? null) !== false) {
                throw new RuntimeException('Fixture media volume is not mounted read-only from the saved deployment');
            }
        }
        return $this->containers[$service] = $id;
    }

    /** @param list<string> $arguments */
    private function execute(array $arguments, #[\SensitiveParameter] ?string $input, float $timeout): string
    {
        $result = $this->docker->run($arguments, input: $input, timeout: $timeout, check: false);
        if ($result->exitCode !== 0 || $result->timedOut || $result->cancelled) {
            throw new RuntimeException('Qualification application operation failed; sensitive command output was withheld');
        }
        return $result->stdout;
    }

    /** @return array<array-key,mixed> */
    private function json(string $output, string $operation): array
    {
        if (strlen($output) > 262144) {
            throw new RuntimeException('Oversized fixture ' . $operation . ' response');
        }
        try {
            $data = json_decode(trim($output), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Invalid fixture ' . $operation . ' response');
        }
        if (!is_array($data) || isset($data['error'])) {
            throw new RuntimeException('Invalid fixture ' . $operation . ' response');
        }
        return $data;
    }

    /** @param array<string,mixed> $dataset */
    private static function datasetRun(array $dataset): string
    {
        $run = $dataset['run'] ?? null;
        if (!is_string($run) || !preg_match('/^[a-z0-9][a-z0-9-]{0,47}$/D', $run)) {
            throw new RuntimeException('Invalid fixture dataset run');
        }
        foreach (['music' => 'music', 'movies' => 'movie', 'episodes' => 'movie'] as $role => $type) {
            if (($dataset['libraries'][$role]['path'] ?? null) !== $run . '/' . $role || ($dataset['libraries'][$role]['type'] ?? null) !== $type) {
                throw new RuntimeException('Invalid fixture library path or type');
            }
        }
        return $run;
    }

    private static function sourcePath(mixed $path, string $run): void
    {
        if (!is_string($path) || !str_starts_with($path, $run . '/') || strlen($path) > 4096 || preg_match('/[\x00-\x1f\x7f\\\\]/', $path)) {
            throw new RuntimeException('Invalid fixture media source path');
        }
        foreach (explode('/', $path) as $part) {
            if (in_array($part, ['', '.', '..'], true)) {
                throw new RuntimeException('Fixture source path escapes its dataset');
            }
        }
    }

    private static function publicId(mixed $id): void
    {
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{21}$/D', $id)) {
            throw new RuntimeException('Invalid fixture catalog public identifier');
        }
    }

    private static function uuid(mixed $id): void
    {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $id)) {
            throw new RuntimeException('Invalid fixture library UUID');
        }
    }

    private static function literal(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
