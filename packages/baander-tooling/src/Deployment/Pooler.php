<?php

declare(strict_types=1);

namespace Baander\Tooling\Deployment;

/**
 * The transaction-mode PgBouncer every deployment reaches PostgreSQL through, run from the
 * database image. Web, ordinary workers and migrations use the application alias, named like
 * the database; worker leases, media authority checks and the scheduler use the control
 * alias and role. Backups, deployment verification and development checkpoints connect to
 * PostgreSQL directly.
 */
final class Pooler
{
    public const string SERVICE = 'pooler';
    public const int PORT = 6432;
    public const string CREDENTIALS_PATH = '/etc/baander-pooler/credentials';
    public const string CONTROL_SUFFIX = '_control';

    /**
     * Server connections per profile, against the client concurrency measured before the
     * pooler: the household profile peaked at about 19 client backends (two web workers with
     * four Doctrine slots each, one consumer, two shared copy jobs and the control children),
     * the full profile at about 30 (four web workers with ten slots each, four consumers and
     * four media jobs). Transaction pooling holds a server connection only for a transaction,
     * so the application pools cover every household web slot at once and half of the full
     * profile's, and further clients wait for the next free connection. The control pools
     * cover two lease agents, the scheduler and each media job's short authority check.
     * The direct reserve serves verification, checkpoints, the pooler credentials step and
     * an operator's psql session. PostgreSQL's max_connections adds its superuser slots.
     */
    public const array POOLS = [
        'household' => ['application' => 10, 'control' => 4, 'direct' => 3],
        'full' => ['application' => 20, 'control' => 8, 'direct' => 4],
    ];

    /** PostgreSQL's max_connections: both pools, the direct reserve and the superuser slots.
     * @param array{application:int,control:int,direct:int} $pools
     */
    public static function maxConnections(array $pools, int $superuserSlots): int
    {
        return $pools['application'] + $pools['control'] + $pools['direct'] + $superuserSlots;
    }

    /** @return array{DATABASE_URL:string,DATABASE_CONTROL_URL:string} */
    public static function urls(string $user, #[\SensitiveParameter] string $password, string $database): array
    {
        $url = static fn(string $user, string $database): string => 'postgresql://' . rawurlencode($user) . ':' . rawurlencode($password)
            . '@' . self::SERVICE . ':' . self::PORT . '/' . rawurlencode($database) . '?serverVersion=18&charset=utf8';
        return [
            'DATABASE_URL' => $url($user, $database),
            'DATABASE_CONTROL_URL' => $url($user . self::CONTROL_SUFFIX, $database . self::CONTROL_SUFFIX),
        ];
    }

    /**
     * The pooler service's environment; baander-pooler renders PgBouncer's configuration from it.
     *
     * @return array<string,string>
     */
    public static function environment(string $profile, string $user, string $database, string $serverHost): array
    {
        return [
            'BAANDER_POOLER_USER' => $user,
            'BAANDER_POOLER_DATABASE' => $database,
            'BAANDER_POOLER_SERVER_HOST' => $serverHost,
            'BAANDER_POOLER_APPLICATION_POOL_SIZE' => (string) self::POOLS[$profile]['application'],
            'BAANDER_POOLER_CONTROL_POOL_SIZE' => (string) self::POOLS[$profile]['control'],
        ];
    }

    /** Covers the database image's data volume, so a pooler container leaves no anonymous volume behind. */
    public const string DATA_TMPFS = '/var/lib/postgresql:rw,noexec,nosuid,size=1m,mode=0755';

    /**
     * Compose service for the pooler. Only it mounts the credentials volume, read-only; it
     * starts once PostgreSQL is healthy and is healthy once it accepts connections.
     *
     * @param array<string,string> $environment
     * @return array<string,mixed>
     */
    public static function service(string $image, array $environment, string $credentialsVolume, string $postgresService): array
    {
        return [
            'image' => $image,
            'user' => 'postgres',
            'entrypoint' => ['baander-pooler'],
            'environment' => $environment,
            'volumes' => [['type' => 'volume', 'source' => $credentialsVolume, 'target' => self::CREDENTIALS_PATH, 'read_only' => true]],
            'tmpfs' => [self::DATA_TMPFS],
            'depends_on' => [$postgresService => ['condition' => 'service_healthy']],
            'healthcheck' => [
                'test' => ['CMD', 'pg_isready', '-h', '127.0.0.1', '-p', (string) self::PORT],
                'interval' => '5s', 'timeout' => '5s', 'retries' => 24,
            ],
        ];
    }

    /**
     * Arguments for a disposable helper from the database image that applies one SCRAM
     * verifier per role over a direct connection and writes the same verifiers to the
     * credentials volume. The caller passes the listed environment names.
     *
     * @return array{arguments:list<string>,environment:array<string,string>}
     */
    public static function credentialsHelper(string $image, string $network, string $credentialsVolume, string $user, #[\SensitiveParameter] string $password, string $database, string $serverHost): array
    {
        $environment = self::credentialsEnvironment($user, $password, $database, $serverHost);
        $arguments = ['--network', $network, '--tmpfs', self::DATA_TMPFS, '--cap-drop', 'ALL', '--cap-add', 'CHOWN', '--cap-add', 'FOWNER', '--cap-add', 'DAC_OVERRIDE',
            '--security-opt', 'no-new-privileges', '--user', '0:0',
            '--mount', 'type=volume,src=' . $credentialsVolume . ',dst=/credentials', '--entrypoint', 'baander-pooler-credentials'];
        foreach ($environment as $key => $_) {
            array_push($arguments, '--env', $key);
        }
        return ['arguments' => [...$arguments, $image, '/credentials'], 'environment' => $environment];
    }

    /** @return array<string,string> */
    public static function credentialsEnvironment(string $user, #[\SensitiveParameter] string $password, string $database, string $serverHost): array
    {
        return ['PGPASSWORD' => $password, 'BAANDER_POOLER_USER' => $user, 'BAANDER_POOLER_DATABASE' => $database, 'BAANDER_POOLER_SERVER_HOST' => $serverHost];
    }
}
