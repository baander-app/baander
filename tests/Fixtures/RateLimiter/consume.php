<?php

declare(strict_types=1);

use App\Shared\Application\Exception\RateLimiterUnavailableException;
use App\Shared\Infrastructure\RateLimiter\RedisRateLimiterFactory;
use App\Shared\Infrastructure\RateLimiter\RedisRateLimiterStore;
use App\Shared\Infrastructure\RateLimiter\RateLimiterSnapshot;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Tests\Fixtures\RateLimiter\RateLimiterFixture;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if (!isset($argv) || count($argv) !== 7) {
    throw new InvalidArgumentException('Expected limiter name, policy, subject, action and barrier arguments.');
}
[$script, $name, $policy, $subject, $action, $barrier, $participant] = $argv;
$dsn = getenv('REDIS_URL');
if (!is_string($dsn) || $dsn === '') {
    throw new RuntimeException('The disposable Redis DSN is required.');
}
$pool = new RedisClientFactory($dsn);
$store = new class($pool, $barrier, $participant) extends RedisRateLimiterStore {
    private bool $waited = false;

    public function __construct(RedisClientFactory $pool, private readonly string $barrier, private readonly string $participant)
    {
        parent::__construct($pool);
    }

    public function read(string $name, string $subject): RateLimiterSnapshot
    {
        $snapshot = parent::read($name, $subject);
        if (!$this->waited && $this->barrier !== '-') {
            $this->waited = true;
            file_put_contents($this->barrier . '/' . $this->participant . '.ready', 'ready');
            $deadline = hrtime(true) + 10_000_000_000;
            while (!is_file($this->barrier . '/go')) {
                if (hrtime(true) >= $deadline) {
                    throw new RuntimeException('The consumer barrier timed out.');
                }
                usleep(1000);
            }
        }

        return $snapshot;
    }
};
$factory = new RedisRateLimiterFactory(RateLimiterFixture::config($name, $policy), $store);
$limiter = $factory->create($subject);
$started = hrtime(true);
try {
    $result = match ($action) {
        'consume' => ['accepted' => $limiter->consume()->isAccepted()],
        'reset' => (static function () use ($limiter): array {
            $limiter->reset();

            return ['reset' => true];
        })(),
        'clear' => (static function () use ($store, $name): array {
            $store->clear($name);

            return ['cleared' => true];
        })(),
        default => throw new InvalidArgumentException('Unknown fixture action.'),
    };
} catch (RateLimiterUnavailableException) {
    $result = ['unavailable' => true];
} finally {
    $pool->dispose();
}
$result['elapsed'] = (hrtime(true) - $started) / 1e9;
echo json_encode($result, JSON_THROW_ON_ERROR);
