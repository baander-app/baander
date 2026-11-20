<?php

declare(strict_types=1);

namespace App\Tests\Integration\Lyrics;

use App\Lyrics\Application\DTO\LrclibResult;
use App\Lyrics\Application\DTO\LrclibWait;
use App\Lyrics\Infrastructure\Api\LrclibClient;
use App\Lyrics\Infrastructure\Redis\RedisLrclibPause;
use Baander\RedisRateLimiter\Exception\RateLimiterUnavailableException;
use Baander\RedisRateLimiter\Limiter\RedisRateLimiterFactory;
use Baander\RedisRateLimiter\Storage\RedisRateLimiterStore;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Reservation;
use Symfony\Component\Yaml\Yaml;

/**
 * The shipped LRCLIB pace and the pause LRCLIB asks for hold across processes that share
 * nothing but Redis, with LRCLIB itself replaced by a mock.
 */
final class LrclibRateLimitTest extends TestCase
{
    /** Seconds the mocked LRCLIB tells a process to wait. */
    private const int PAUSE_SECONDS = 3;
    private const string CHILD = <<<'PHP'
        require $argv[1];
        echo json_encode(\App\Tests\Integration\Lyrics\LrclibRateLimitTest::fetchInChild($argv[2], $argv[3], $argv[4], $argv[5], $argv[6], (float) $argv[7], json_decode($argv[8], true, flags: JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
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
        $this->name = 'lrclib-acceptance-' . (random_bytes(12) |> bin2hex(...));
        $this->directory = sys_get_temp_dir() . '/baander-lrclib-' . (random_bytes(12) |> bin2hex(...));
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
            self::pausePrefix($this->name) . ':pause',
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

    public function testTwoProcessesCallingAtOnceGetTurnsAtLeastTwoSecondsApart(): void
    {
        $results = $this->fetchAfterTheBarrier([
            'first' => ['after' => '', 'delay' => 0.0, 'answers' => [[200, null]]],
            'second' => ['after' => '', 'delay' => 0.0, 'answers' => [[200, null]]],
        ]);

        $turns = self::turns($results);
        self::assertCount(2, $turns);
        self::assertGreaterThanOrEqual(2.0, $turns[1] - $turns[0]);
        foreach ($results as $result) {
            self::assertTrue($result['found']);
            self::assertCount(1, $result['sends']);
        }
    }

    public function testAPauseOneProcessStoresHoldsTheOtherAndItsRequestIsSentAgainAfterwards(): void
    {
        // LRCLIB tells the first process to wait. The second asks half a second after that
        // request went out, while the pause is in effect.
        $results = $this->fetchAfterTheBarrier([
            'limited' => ['after' => '', 'delay' => 0.0, 'answers' => [[429, self::PAUSE_SECONDS], [200, null]]],
            'other' => ['after' => 'limited', 'delay' => 0.5, 'answers' => [[200, null]]],
        ]);

        [$limited, $other] = [$results['limited'], $results['other']];
        self::assertTrue($limited['found'], 'The request that drew the rate limit is answered once it is sent again.');
        self::assertTrue($other['found']);
        self::assertCount(2, $limited['sends']);
        self::assertCount(1, $other['sends']);
        $pausedAt = $limited['sends'][0]['at'];
        // Redis counts the pause in whole milliseconds from when it stored it.
        foreach ([$limited['sends'][1]['at'], $other['sends'][0]['at']] as $sentAt) {
            self::assertGreaterThanOrEqual(self::PAUSE_SECONDS - 0.002, $sentAt - $pausedAt, 'No request leaves a process during the pause.');
        }
        $turns = self::turns($results);
        for ($turn = 1; $turn < count($turns); ++$turn) {
            self::assertGreaterThanOrEqual(2.0, $turns[$turn] - $turns[$turn - 1]);
        }
    }

    public function testALaterAnswerNeverShortensAStoredPause(): void
    {
        $pool = new RedisClientFactory($this->dsn);
        try {
            $pause = new RedisLrclibPause($pool, self::pausePrefix($this->name));

            self::assertSame(0.0, $pause->secondsLeft());
            self::assertSame(0.0, $pause->pauseFor(0), 'An answer naming no wait stores no pause.');
            self::assertSame(20.0, $pause->pauseFor(20));
            self::assertEqualsWithDelta(20.0, $pause->pauseFor(5), 1.0, 'A shorter pause leaves the longer one in effect.');
            self::assertEqualsWithDelta(20.0, $pause->secondsLeft(), 1.0);
            self::assertSame(30.0, $pause->pauseFor(30), 'A longer pause replaces the shorter one.');
            self::assertEqualsWithDelta(30.0, new RedisLrclibPause($pool, self::pausePrefix($this->name))->secondsLeft(), 1.0);
        } finally {
            $pool->dispose();
        }
    }

    public function testAPauseThatCannotBeReachedIsReportedAsTheRateLimitBeingUnavailable(): void
    {
        $pause = new RedisLrclibPause(
            new RedisClientFactory($this->dsn, connectionFactory: static fn(): \Redis => throw new \RedisException('Connection refused')),
            self::pausePrefix($this->name),
        );

        try {
            $pause->secondsLeft();
            self::fail('A pause that cannot be read must not read as no pause.');
        } catch (RateLimiterUnavailableException) {
        }
        $this->expectException(RateLimiterUnavailableException::class);
        $pause->pauseFor(5);
    }

    /**
     * Runs in a child process: waits at the barrier, then until the named participant has sent
     * a request, then for its delay, and fetches lyrics through the client on the shared Redis
     * limit and pause.
     *
     * @param string $after the participant whose first request this one waits for; empty for none
     * @param list<array{int, ?int}> $answers the status and Retry-After seconds of each answer the mocked LRCLIB gives, in order
     *
     * @return array{subjects: list<string>, turns: list<float>, sends: list<array{at: float, turn: float}>, found: bool}
     */
    public static function fetchInChild(string $dsn, string $name, string $directory, string $participant, string $after, float $delay, array $answers): array
    {
        $pool = new RedisClientFactory($dsn);
        $subjects = [];
        $turns = [];
        $sends = [];
        try {
            $limiter = self::recording(
                new RedisRateLimiterFactory(self::shippedConfig($name), new RedisRateLimiterStore($pool->borrow(...))),
                static function (string $subject, float $turn) use (&$subjects, &$turns): void {
                    $subjects[] = $subject;
                    $turns[] = $turn;
                },
            );
            $transport = new MockHttpClient(static function () use (&$answers, &$turns, &$sends, $directory, $participant): MockResponse {
                $sends[] = ['at' => microtime(true), 'turn' => array_last($turns) ?? throw new \RuntimeException('A request was sent without a turn.')];
                file_put_contents($directory . '/' . $participant . '.sent', 'sent');
                [$status, $retryAfter] = array_shift($answers) ?? throw new \RuntimeException('No answer is left for the request.');

                return new MockResponse(
                    $status === 200 ? json_encode(['id' => 912345, 'trackName' => 'Still Alive', 'plainLyrics' => 'This was a triumph'], JSON_THROW_ON_ERROR) : '',
                    ['http_code' => $status, 'response_headers' => $retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter]],
                );
            });
            $client = new LrclibClient($transport, new NullLogger(), $limiter, new RedisLrclibPause($pool, self::pausePrefix($name)), new NativeClock(), 'https://lrclib.baander.app');
            file_put_contents($directory . '/' . $participant . '.ready', 'ready');
            self::waitFor($directory . '/go');
            if ($after !== '') {
                self::waitFor($directory . '/' . $after . '.sent');
            }
            if ($delay > 0) {
                usleep((int) ($delay * 1e6));
            }

            $found = $client->getById(912345, LrclibWait::Queued) instanceof LrclibResult;

            return ['subjects' => $subjects, 'turns' => $turns, 'sends' => $sends, 'found' => $found];
        } finally {
            $pool->dispose();
        }
    }

    private static function waitFor(string $file): void
    {
        $deadline = hrtime(true) + 15_000_000_000;
        while (!is_file($file)) {
            if (hrtime(true) >= $deadline) {
                throw new \RuntimeException('Waiting for ' . basename($file) . ' timed out.');
            }
            usleep(1000);
        }
    }

    /**
     * Starts one child per participant, releases them together, and returns what each reports
     * once its fetch has ended.
     *
     * @param array<string, array{after: string, delay: float, answers: list<array{int, ?int}>}> $participants whose first request each
     *        waits for, the seconds it then waits, and LRCLIB's answers to it
     *
     * @return array<string, array{subjects: list<string>, turns: list<float>, sends: list<array{at: float, turn: float}>, found: bool}>
     */
    private function fetchAfterTheBarrier(array $participants): array
    {
        $children = array_map(
            fn(string $participant, array $plan): Process => $this->child($participant, $plan['after'], $plan['delay'], $plan['answers']),
            array_keys($participants),
            $participants,
        );
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

        $results = array_combine(array_keys($participants), array_map($this->finish(...), $children));
        $this->subjects = array_merge(...array_column($results, 'subjects')) |> array_unique(...) |> array_values(...);

        self::assertCount(1, $this->subjects, 'Every process spends the same budget.');
        foreach (array_merge(...array_column($results, 'sends')) as $send) {
            self::assertGreaterThanOrEqual($send['turn'], $send['at'], 'A request waits for its turn.');
        }

        return $results;
    }

    /**
     * Every turn the processes reserved, in order.
     *
     * @param array<string, array{turns: list<float>}> $results
     *
     * @return list<float>
     */
    private static function turns(array $results): array
    {
        $turns = array_merge(...array_column($results, 'turns'));
        sort($turns);

        return $turns;
    }

    /** @param list<array{int, ?int}> $answers */
    private function child(string $participant, string $after, float $delay, array $answers): Process
    {
        $child = new Process([
            PHP_BINARY, '-r', self::CHILD,
            dirname(__DIR__, 3) . '/vendor/autoload.php', $this->dsn, $this->name, $this->directory, $participant, $after, (string) $delay,
            json_encode($answers, JSON_THROW_ON_ERROR),
        ], timeout: 30);
        $this->children[] = $child;

        return $child;
    }

    /** @return array{subjects: list<string>, turns: list<float>, sends: list<array{at: float, turn: float}>, found: bool} */
    private function finish(Process $child): array
    {
        self::assertSame(0, $child->wait(), $child->getErrorOutput());
        self::assertSame('', $child->getErrorOutput());
        $result = json_decode($child->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);

        return $result;
    }

    /**
     * The shipped `lrclib` limiter configuration under a name of this test's own.
     *
     * @return array<string, mixed>
     */
    private static function shippedConfig(string $name): array
    {
        $config = Yaml::parseFile(dirname(__DIR__, 3) . '/config/packages/framework.yaml')['framework']['rate_limiter']['lrclib'];

        return ['id' => $name, ...$config];
    }

    /** The key prefix of this test's own pause. */
    private static function pausePrefix(string $name): string
    {
        return 'lyrics:lrclib:' . $name;
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
