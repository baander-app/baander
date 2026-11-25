<?php

declare(strict_types=1);

/*
 * Child process of LoginFailureReservationRaceTest: one wrong-password sign-in against the
 * per-account failure limit, as PasswordAuthenticator charges it, over the shared Redis.
 *
 * Arguments: the limiter name, the barrier directory and this participant's name. Every
 * participant waits at the barrier after its first read of the limiter, so all of them read
 * the same state before any of them writes. Prints whether the password would have been
 * checked, or that the limiter was unavailable, as JSON.
 */

use App\Auth\Infrastructure\Security\RateLimiterLoginFailureThrottle;
use App\Shared\Domain\Model\Email;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Tests\Integration\LoginFailureReservationRaceTest;
use Baander\RedisRateLimiter\Exception\RateLimiterUnavailableException;
use Baander\RedisRateLimiter\Limiter\RedisRateLimiterFactory;
use Baander\RedisRateLimiter\Storage\RateLimiterSnapshot;
use Baander\RedisRateLimiter\Storage\RedisRateLimiterStore;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if (!isset($argv) || count($argv) !== 4) {
    throw new InvalidArgumentException('Expected the limiter name, the barrier directory and the participant.');
}
[, $name, $barrier, $participant] = $argv;
$dsn = getenv('REDIS_URL');
if (!is_string($dsn) || $dsn === '') {
    throw new RuntimeException('The disposable Redis DSN is required.');
}
$pool = new RedisClientFactory($dsn);
$store = new class($pool, $barrier, $participant) extends RedisRateLimiterStore {
    private bool $waited = false;

    public function __construct(RedisClientFactory $pool, private readonly string $barrier, private readonly string $participant)
    {
        parent::__construct($pool->borrow(...));
    }

    public function read(string $name, string $subject): RateLimiterSnapshot
    {
        $snapshot = parent::read($name, $subject);
        if (!$this->waited) {
            $this->waited = true;
            file_put_contents($this->barrier . '/' . $this->participant . '.ready', 'ready');
            $deadline = hrtime(true) + 20_000_000_000;
            while (!is_file($this->barrier . '/go')) {
                if (hrtime(true) >= $deadline) {
                    throw new RuntimeException('The barrier timed out.');
                }
                usleep(1000);
            }
        }

        return $snapshot;
    }
};
$throttle = new RateLimiterLoginFailureThrottle(new RedisRateLimiterFactory(LoginFailureReservationRaceTest::config($name), $store));
$email = new Email(LoginFailureReservationRaceTest::EMAIL);
$checked = false;
$unavailable = false;
try {
    // The password is checked only once the failure is reserved, and the check is wrong, so it stays charged.
    $checked = $throttle->reserve($email) === null;
} catch (RateLimiterUnavailableException) {
    $unavailable = true;
} finally {
    $pool->dispose();
}
echo json_encode(['checked' => $checked, 'unavailable' => $unavailable], JSON_THROW_ON_ERROR);
