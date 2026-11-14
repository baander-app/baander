<?php

declare(strict_types=1);

namespace App\Tests\Integration\Metadata;

use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzSlotWait;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Metadata\Infrastructure\Api\CoverArtArchive\CoverArtArchiveAdapter;
use App\Metadata\Infrastructure\Api\MusicBrainz\MusicBrainzAdapter;
use App\Shared\Infrastructure\Http\PublicDestinationPolicy;
use App\Shared\Infrastructure\Http\PublicHttpClient;
use App\Shared\Infrastructure\RateLimiter\RedisRateLimiterFactory;
use App\Shared\Infrastructure\RateLimiter\RedisRateLimiterStore;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Reservation;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Yaml\Yaml;

/**
 * The shipped MusicBrainz limit holds across processes that share nothing but Redis, with
 * MusicBrainz itself replaced by a mock.
 */
final class MusicBrainzRateLimitTest extends TestCase
{
    private const string RELEASE_ID = 'f3c3a3b8-5d1e-4c55-9a0e-7a51f4c2d001';
    private const string CHILD = <<<'PHP'
        require $argv[1];
        echo json_encode(\App\Tests\Integration\Metadata\MusicBrainzRateLimitTest::lookUpInChild($argv[2], $argv[3], $argv[4], $argv[5]), JSON_THROW_ON_ERROR);
        PHP;

    private string $dsn;
    private string $name;
    private string $directory;
    /** @var list<string> limiter subjects whose Redis keys this test removes */
    private array $subjects = [];
    /** @var list<Process> */
    private array $children = [];

    protected function setUp(): void
    {
        $dsn = getenv('REDIS_URL');
        self::assertIsString($dsn, 'Run this test with the disposable Redis runner.');
        self::assertNotSame('', $dsn);
        $this->dsn = $dsn;
        $this->name = 'musicbrainz-acceptance-' . (random_bytes(12) |> bin2hex(...));
        $this->directory = sys_get_temp_dir() . '/baander-musicbrainz-' . (random_bytes(12) |> bin2hex(...));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            if ($child->isRunning()) {
                $child->stop(0);
            }
        }
        // Only this test's keys are removed; never scan or flush the service.
        $config = self::shippedConfig($this->name);
        $namespace = RedisRateLimiterStore::namespace($this->name);
        $keys = [
            $namespace . ':generation',
            ...array_map(
                static fn(string $subject): string => $namespace . ':subject:' . hash('sha256', serialize($config) . "\0" . $subject),
                $this->subjects,
            ),
        ];
        $pool = new RedisClientFactory($this->dsn);
        try {
            $pool->borrow(static function (\Redis $redis) use ($keys): void {
                $redis->del(...$keys);
            });
        } finally {
            $pool->dispose();
            array_map(unlink(...), glob($this->directory . '/*') ?: []);
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function testTwoProcessesCallingAtOnceGetTurnsAtLeastOneSecondApart(): void
    {
        $children = array_map($this->child(...), ['first', 'second']);
        foreach ($children as $child) {
            $child->start();
        }
        $deadline = hrtime(true) + 15_000_000_000;
        while (count(glob($this->directory . '/*.ready') ?: []) < count($children)) {
            foreach ($children as $child) {
                $child->checkTimeout();
                self::assertTrue($child->isRunning(), 'A child exited before the barrier: ' . $child->getErrorOutput());
            }
            self::assertLessThan($deadline, hrtime(true), 'The children did not reach the barrier.');
            usleep(10_000);
        }
        file_put_contents($this->directory . '/go', 'go');

        $results = array_map($this->finish(...), $children);
        $this->subjects = array_column($results, 'subject') |> array_unique(...) |> array_values(...);

        self::assertCount(1, $this->subjects, 'Both processes spend the same budget.');
        foreach ($results as $result) {
            self::assertGreaterThanOrEqual($result['turn'], $result['requestedAt'], 'A request waits for its turn.');
        }
        $turns = array_column($results, 'turn');
        self::assertGreaterThanOrEqual(1.0, max($turns) - min($turns));
    }

    public function testABoundedCallWhoseTurnIsMoreThanTenSecondsAwayAnswersUnavailableAtOnce(): void
    {
        $this->subjects = ['bounded'];
        $pool = new RedisClientFactory($this->dsn);
        try {
            $shared = new RedisRateLimiterFactory(self::shippedConfig($this->name), new RedisRateLimiterStore($pool));
            // Other processes have taken the next eleven turns.
            for ($turn = 0; $turn < 11; ++$turn) {
                $shared->create('bounded')->reserve();
            }
            $requests = 0;
            $adapter = self::adapter(self::subject($shared, 'bounded'), static function () use (&$requests): MockResponse {
                ++$requests;

                return new MockResponse('{}');
            });
            $started = hrtime(true);

            $result = $adapter->fetchRelease(self::RELEASE_ID, MusicBrainzSlotWait::Bounded);

            self::assertInstanceOf(MusicBrainzUnavailable::class, $result);
            self::assertSame(0, $requests);
            self::assertLessThan(2.0, (hrtime(true) - $started) / 1e9);
        } finally {
            $pool->dispose();
        }
    }

    /**
     * Runs in a child process: waits at the barrier, then looks a release up through the
     * adapter on the shared Redis limit.
     *
     * @return array{subject: string, turn: float, requestedAt: float}
     */
    public static function lookUpInChild(string $dsn, string $name, string $directory, string $participant): array
    {
        $pool = new RedisClientFactory($dsn);
        $turns = [];
        try {
            $shared = new RedisRateLimiterFactory(self::shippedConfig($name), new RedisRateLimiterStore($pool));
            $recorded = self::recording($shared, static function (string $subject, float $turn) use (&$turns): void {
                $turns[] = [$subject, $turn];
            });
            $requestedAt = null;
            $adapter = self::adapter($recorded, static function () use (&$requestedAt): MockResponse {
                $requestedAt = microtime(true);

                return new MockResponse(json_encode(['id' => self::RELEASE_ID, 'title' => 'F-1 Trillion'], JSON_THROW_ON_ERROR));
            });
            file_put_contents($directory . '/' . $participant . '.ready', 'ready');
            $deadline = hrtime(true) + 15_000_000_000;
            while (!is_file($directory . '/go')) {
                if (hrtime(true) >= $deadline) {
                    throw new \RuntimeException('The barrier timed out.');
                }
                usleep(1000);
            }

            if (!$adapter->fetchRelease(self::RELEASE_ID, MusicBrainzSlotWait::Unbounded) instanceof MusicBrainzRelease || $requestedAt === null) {
                throw new \RuntimeException('The lookup failed.');
            }
            [$subject, $turn] = $turns[0] ?? throw new \RuntimeException('No turn was reserved.');

            return ['subject' => $subject, 'turn' => $turn, 'requestedAt' => $requestedAt];
        } finally {
            $pool->dispose();
        }
    }

    private function child(string $participant): Process
    {
        $child = new Process([
            PHP_BINARY, '-r', self::CHILD,
            dirname(__DIR__, 3) . '/vendor/autoload.php', $this->dsn, $this->name, $this->directory, $participant,
        ], timeout: 30);
        $this->children[] = $child;

        return $child;
    }

    /** @return array{subject: string, turn: float, requestedAt: float} */
    private function finish(Process $child): array
    {
        self::assertSame(0, $child->wait(), $child->getErrorOutput());
        self::assertSame('', $child->getErrorOutput());
        $result = json_decode($child->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);

        return $result;
    }

    /** @param \Closure(): MockResponse $respond */
    private static function adapter(RateLimiterFactoryInterface $limiter, \Closure $respond): MusicBrainzAdapter
    {
        $client = new PublicHttpClient(
            new MockHttpClient(static fn(): MockResponse => $respond()),
            new PublicDestinationPolicy(static fn(string $host): array => ['1.1.1.1']),
        );
        $logger = new NullLogger();

        return new MusicBrainzAdapter($logger, new JsonEncoder(), $client, $limiter, new CoverArtArchiveAdapter($logger, new JsonEncoder(), $client));
    }

    /**
     * The shipped `musicbrainz` limiter configuration under a name of this test's own.
     *
     * @return array<string, mixed>
     */
    private static function shippedConfig(string $name): array
    {
        $config = Yaml::parseFile(dirname(__DIR__, 3) . '/config/packages/framework.yaml')['framework']['rate_limiter']['musicbrainz'];

        return ['id' => $name, ...$config];
    }

    /** Hands out the limiter of one subject whatever key is asked for. */
    private static function subject(RateLimiterFactoryInterface $factory, string $subject): RateLimiterFactoryInterface
    {
        return new class($factory, $subject) implements RateLimiterFactoryInterface {
            public function __construct(private readonly RateLimiterFactoryInterface $factory, private readonly string $subject)
            {
            }

            public function create(?string $key = null): LimiterInterface
            {
                return $this->factory->create($this->subject);
            }
        };
    }

    /**
     * Tells the subject and the time to act of every turn reserved through the factory.
     *
     * @param \Closure(string, float): void $onTurn
     */
    private static function recording(RateLimiterFactoryInterface $factory, \Closure $onTurn): RateLimiterFactoryInterface
    {
        return new class($factory, $onTurn) implements RateLimiterFactoryInterface {
            /** @param \Closure(string, float): void $onTurn */
            public function __construct(private readonly RateLimiterFactoryInterface $factory, private readonly \Closure $onTurn)
            {
            }

            public function create(?string $key = null): LimiterInterface
            {
                return new class($this->factory->create($key), (string) $key, $this->onTurn) implements LimiterInterface {
                    /** @param \Closure(string, float): void $onTurn */
                    public function __construct(private readonly LimiterInterface $limiter, private readonly string $subject, private readonly \Closure $onTurn)
                    {
                    }

                    public function reserve(int $tokens = 1, ?float $maxTime = null): Reservation
                    {
                        $reservation = $this->limiter->reserve($tokens, $maxTime);
                        ($this->onTurn)($this->subject, $reservation->getTimeToAct());

                        return $reservation;
                    }

                    public function consume(int $tokens = 1): RateLimit
                    {
                        return $this->limiter->consume($tokens);
                    }

                    public function reset(): void
                    {
                        $this->limiter->reset();
                    }
                };
            }
        };
    }
}
