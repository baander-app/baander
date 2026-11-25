<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Infrastructure\Redis\RedisClientFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;

/**
 * The test stack's Redis carries the preset's ACL: `default` cannot authenticate and the
 * application user has only the commands the application issues. Every client of the
 * application authenticates as the user its DSN names.
 */
final class RedisApplicationIdentityTest extends KernelTestCase
{
    /** @var list<string> */
    private array $users = [];

    /** @var list<string> */
    private array $keys = [];

    /** @var list<Connection> */
    private array $streams = [];

    public function testTheDefaultUserCannotAuthenticate(): void
    {
        $password = $this->applicationPassword();

        foreach ([$password, ['default', $password]] as $credentials) {
            $redis = $this->connect();
            try {
                self::assertFalse(@$redis->auth($credentials));
            } catch (\RedisException $refused) {
                self::assertStringContainsString('WRONGPASS', $refused->getMessage());
            } finally {
                $redis->close();
            }
        }
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function commandsOutsideTheAcl(): iterable
    {
        yield 'FLUSHALL' => ['FLUSHALL', []];
        yield 'KEYS' => ['KEYS', ['*']];
        yield 'CONFIG GET' => ['CONFIG', ['GET', 'dir']];
        yield 'ACL SETUSER' => ['ACL', ['SETUSER', 'intruder', 'on', '>intruder-password', '+@all']];
        yield 'CLIENT KILL' => ['CLIENT', ['KILL', 'USER', 'admin']];
        yield 'PUBLISH' => ['PUBLISH', ['channel', 'message']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('commandsOutsideTheAcl')]
    public function testACommandOutsideTheAclFails(string $command, array $arguments): void
    {
        $factory = new RedisClientFactory($this->environment('REDIS_URL'));
        try {
            $factory->borrow(static function (\Redis $redis) use ($command, $arguments): void {
                try {
                    $redis->rawCommand($command, ...$arguments);
                    self::fail($command . ' ran as the application user.');
                } catch (\RedisException $refused) {
                    self::assertStringContainsString('NOPERM', $refused->getMessage());
                }
            });
        } finally {
            $factory->dispose();
        }
    }

    public function testTheCacheSessionMessengerAndLimiterClientsConnectAsTheApplicationUser(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $marker = 'identity-' . (random_bytes(8) |> bin2hex(...));

        $factory = $container->get(RedisClientFactory::class);
        self::assertInstanceOf(RedisClientFactory::class, $factory);
        $this->keys[] = $marker;
        self::assertTrue($factory->borrow(static fn (\Redis $redis): bool => $redis->set($marker, 'limiter')));

        $pool = $container->get('doctrine.result_cache_pool');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        self::assertTrue($pool->save($pool->getItem($marker)->set('cache')));
        self::assertTrue($pool->deleteItem($marker));

        $session = $container->get('redis.session.client');
        self::assertInstanceOf(\Redis::class, $session);
        $this->keys[] = $marker . '-session';
        self::assertTrue($session->set($marker . '-session', 'session'));

        $stream = $this->stream($this->environment('MESSENGER_TEST_REDIS_DSN'));
        $stream->add('{}', []);
        self::assertNotNull($stream->get());

        $users = $this->clientUsers();
        self::assertNotContains('default', $users);
        self::assertContains('baander', $users);
    }

    public function testAPasswordWithAtAndPercentSignsConnectsEveryClient(): void
    {
        $user = 'identity_' . (random_bytes(6) |> bin2hex(...));
        $password = 'p@ss%word/with:separators-' . (random_bytes(4) |> bin2hex(...));
        $this->users[] = $user;
        $this->admin()->rawCommand('ACL', 'SETUSER', $user, 'on', '>' . $password, ...explode(' ', self::applicationRules()));
        $dsn = 'redis://' . $user . ':' . rawurlencode($password) . '@' . parse_url($this->environment('REDIS_URL'), PHP_URL_HOST) . ':6379';
        $marker = 'identity-' . (random_bytes(8) |> bin2hex(...));
        $this->keys[] = $marker;

        $factory = new RedisClientFactory($dsn);
        try {
            self::assertTrue($factory->borrow(static fn (\Redis $redis): bool => $redis->set($marker, 'factory')));
            $sessions = new RedisSessionHandler($factory->createStandaloneConnection());
            self::assertTrue($sessions->open('', 'BAANDERSESSID'));
            self::assertTrue($sessions->write($marker . '-session', 'session'));
            self::assertSame('session', $sessions->read($marker . '-session'));
            self::assertTrue($sessions->destroy($marker . '-session'));
        } finally {
            $factory->dispose();
        }

        $pool = new RedisAdapter(RedisAdapter::createConnection($dsn . '?timeout=1&read_timeout=1'), 'identity_' . (random_bytes(6) |> bin2hex(...)));
        self::assertTrue($pool->save($pool->getItem('entry')->set('cache')));
        self::assertSame('cache', $pool->getItem('entry')->get());
        self::assertTrue($pool->clear());

        $stream = $this->stream($dsn);
        $stream->add('{}', []);
        self::assertNotNull($stream->get());
    }

    /** The application user's rules in the test stack, as compose.test.yaml grants them. */
    private static function applicationRules(): string
    {
        $compose = (string) file_get_contents(dirname(__DIR__, 2) . '/compose.test.yaml');
        self::assertSame(1, preg_match('/--user baander on >\S+ (.+?) --user admin/', $compose, $match));

        return $match[1];
    }

    /** @return list<string> The user of every client connected to Redis. */
    private function clientUsers(): array
    {
        $clients = (string) $this->admin()->rawCommand('CLIENT', 'LIST');
        preg_match_all('/\buser=(\S+)/', $clients, $users);

        return $users[1];
    }

    private function stream(string $dsn): Connection
    {
        $connection = Connection::fromDsn($dsn, ['stream' => 'identity_' . (random_bytes(8) |> bin2hex(...)), 'group' => 'identity', 'consumer' => 'identity', 'delete_after_ack' => true]);
        $connection->setup();
        $this->streams[] = $connection;

        return $connection;
    }

    private function applicationPassword(): string
    {
        return rawurldecode((string) parse_url($this->environment('REDIS_URL'), PHP_URL_PASS));
    }

    private function connect(): \Redis
    {
        $redis = new \Redis();
        $redis->connect((string) parse_url($this->environment('REDIS_URL'), PHP_URL_HOST), 6379, 2.0);

        return $redis;
    }

    private function admin(): \Redis
    {
        return new RedisClientFactory($this->environment('REDIS_TEST_ADMIN_DSN'))->createStandaloneConnection();
    }

    private function environment(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            self::markTestSkipped('Run this test in the disposable stack, which sets ' . $name . '.');
        }

        return $value;
    }

    protected function tearDown(): void
    {
        foreach ($this->streams as $connection) {
            $connection->cleanup();
            $connection->close();
        }
        if ($this->users !== [] || $this->keys !== []) {
            $admin = $this->admin();
            foreach ($this->users as $user) {
                $admin->rawCommand('ACL', 'DELUSER', $user);
            }
            if ($this->keys !== []) {
                $admin->del($this->keys);
            }
            $admin->close();
        }
        parent::tearDown();
    }
}
