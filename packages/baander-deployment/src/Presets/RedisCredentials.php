<?php

declare(strict_types=1);

namespace Baander\Deployment\Presets;

use Baander\Core\EnvironmentVariable;

/**
 * The preset's Redis identity. Redis runs as its image's `redis` user and loads its users
 * from an ACL file on a credentials volume: `default` cannot authenticate, and the
 * application authenticates as `baander` with the commands its clients issue. A disposable
 * helper from the Redis image writes that file, holding only the password's SHA-256, and
 * gives the data volume to the `redis` user. It receives the password by environment name.
 */
final class RedisCredentials
{
    public const string SERVICE = 'redis';
    public const string USER = 'baander';
    public const string DIRECTORY = '/etc/baander-redis';
    public const string ACL_FILE = self::DIRECTORY . '/users.acl';
    /** The image user Redis runs as; the helper hands it the ACL file and the data volume. */
    public const string RUN_AS = 'redis';

    /**
     * Strings, hashes, sorted sets, streams and keys through `@read` and `@write`, without
     * `@dangerous` (KEYS, FLUSHALL, CONFIG, DEBUG and the like). EVAL runs the rate limiter,
     * queue and Messenger scripts, which call TIME; INFO serves the server statistics and the
     * cache pools' clear(). No pub/sub channel is used.
     */
    public const string APPLICATION_RULES = '~* resetchannels -@all +@read +@write -@dangerous +eval +time +ping +select +info';

    private const string HELPER = <<<'SH'
id -u redis >/dev/null
umask 077
digest=$(printf '%s' "$REDIS_PASSWORD" | sha256sum | cut -d ' ' -f 1)
printf '%s\n' 'user default reset' "user @USER@ on #$digest @RULES@" > /credentials/users.acl.tmp
chown redis:redis /credentials/users.acl.tmp
mv /credentials/users.acl.tmp /credentials/users.acl
chown redis:redis /credentials
chmod 0700 /credentials
chown -R redis:redis /data
SH;

    public static function url(#[\SensitiveParameter] string $password): string
    {
        return 'redis://' . self::USER . ':' . rawurlencode($password) . '@' . self::SERVICE . ':6379';
    }

    /** The ACL file the helper writes for a password whose SHA-256 is given. */
    public static function aclFile(string $passwordDigest): string
    {
        return "user default reset\nuser " . self::USER . ' on #' . $passwordDigest . ' ' . self::APPLICATION_RULES . "\n";
    }

    /**
     * Arguments for the disposable helper: the Redis image as root with no network, the
     * credentials volume at /credentials and the data volume at /data. The caller passes the
     * listed environment names.
     *
     * @return array{arguments:list<string>,environment:array<string,string>}
     */
    public static function helper(string $image, string $credentialsVolume, string $dataVolume, #[\SensitiveParameter] string $password): array
    {
        $script = strtr(self::HELPER, ['@USER@' => self::USER, '@RULES@' => self::APPLICATION_RULES]);

        return [
            'arguments' => ['--network', 'none', '--cap-drop', 'ALL', '--cap-add', 'CHOWN', '--cap-add', 'FOWNER', '--cap-add', 'DAC_OVERRIDE',
                '--security-opt', 'no-new-privileges', '--user', '0:0',
                '--mount', 'type=volume,src=' . $credentialsVolume . ',dst=/credentials',
                '--mount', 'type=volume,src=' . $dataVolume . ',dst=/data',
                '--env', EnvironmentVariable::REDIS_PASSWORD, '--entrypoint', 'sh', $image, '-ec', $script],
            'environment' => [EnvironmentVariable::REDIS_PASSWORD => $password],
        ];
    }

    /**
     * Redis answers NOAUTH to a client that has not authenticated, so a reply proves it serves
     * without the healthcheck holding a credential.
     *
     * @return array<string,mixed>
     */
    public static function healthcheck(): array
    {
        return ['test' => ['CMD-SHELL', 'redis-cli ping 2>&1 | grep -q NOAUTH'], 'interval' => '5s', 'timeout' => '5s', 'retries' => 24];
    }
}
