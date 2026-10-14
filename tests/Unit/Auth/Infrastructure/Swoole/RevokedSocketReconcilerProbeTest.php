<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Swoole;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Runs the reconciler's real Swoole timer in a child process: every tick queries the
 * database in a coroutine the swoole bundle does not manage, so the tick must give back
 * the pooled services that coroutine took.
 */
final class RevokedSocketReconcilerProbeTest extends TestCase
{
    public function testEachTickReleasesThePooledServicesItsCoroutineTook(): void
    {
        self::assertTrue(extension_loaded('swoole'), 'The reconciler timer requires Swoole.');
        $script = <<<'PHP'
require $argv[1];
final class RecordingPool implements SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePool {
    /** @var list<int> */
    public array $released = [];
    public function get(): object { return new stdClass(); }
    public function releaseFromCoroutine(int $cId): void { $this->released[] = $cId; }
    public function getAssignedCount(): int { return 0; }
    public function getFreeCount(): int { return 0; }
    public function getInstancesLimit(): int { return 1; }
}
// Each run asks the database once, from the tick's coroutine.
final class TickRepository implements App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface {
    /** @var list<int> */
    public array $ticks = [];
    public ?Closure $afterQuery = null;
    public function findTokenIdsOfLiveChains(array $tokenIds): array {
        $this->ticks[] = Swoole\Coroutine::getCid();
        ($this->afterQuery)?->__invoke(count($this->ticks));
        return [];
    }
    public function save(App\Auth\Domain\Model\OAuth\AccessToken $accessToken, bool $flush = true): void {}
    public function findByTokenId(App\Auth\Domain\Model\OAuth\TokenId $tokenId): ?App\Auth\Domain\Model\OAuth\AccessToken { return null; }
    public function findTokenIdsByChainId(App\Auth\Domain\Model\OAuth\ValueObject\ChainId $chainId): array { return []; }
    public function revokeByChainId(App\Auth\Domain\Model\OAuth\ValueObject\ChainId $chainId): void {}
    public function revokeByIds(array $ids): int { return 0; }
    public function revokeForUser(App\Shared\Domain\Model\Uuid $userId, ?App\Auth\Domain\Model\OAuth\AccessToken $keep = null): void {}
    public function revokeByClientId(App\Shared\Domain\Model\Uuid $clientId): void {}
}
final class CountingConnections implements App\Shared\Application\Port\LiveConnectionsPortInterface {
    /** @var list<list<string>> */
    public array $closed = [];
    public function closeForUser(App\Shared\Domain\Model\Uuid $userId): int { return 0; }
    public function closeForAccessTokens(array $accessTokenIds): int { $this->closed[] = $accessTokenIds; return 1; }
}
$pool = new RecordingPool();
$repository = new TickRepository();
$connections = new CountingConnections();
$registry = App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry::create(maxConnections: 8, maxRoomMembers: 8);
$registry->addConnection(1, 'user-1', 0, 'token-dead');
$reconciler = new App\Auth\Infrastructure\Swoole\RevokedSocketReconciler(
    $registry,
    $repository,
    $connections,
    new Psr\Log\NullLogger(),
    1,
    new SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper(
        new SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer([0 => [$pool]]),
        new SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole(),
    ),
);
// A constructed Swoole\Server keeps Event::wait() from running the reactor; the
// events only need an HTTP worker's server object.
$server = (new ReflectionClass(Swoole\Server::class))->newInstanceWithoutConstructor();
$watchdog = Swoole\Timer::after(4000, static function (): void {
    fwrite(STDERR, "Timer did not terminate.\n");
    exit(1);
});
$repository->afterQuery = static function (int $ticks) use ($reconciler, $server, $watchdog): void {
    if ($ticks === 2) {
        $reconciler->onWorkerStopped(new SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStoppedEvent($server, 0));
        Swoole\Timer::clear($watchdog);
    }
};
$reconciler->onWorkerStarted(new SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent($server, 0));
Swoole\Event::wait();
$ticks = $repository->ticks;
if (count($ticks) !== 2 || min($ticks) < 1 || count(array_unique($ticks)) !== 2 || $pool->released !== $ticks) {
    fwrite(STDERR, sprintf("Ticks ran in coroutines %s; released %s.\n", json_encode($ticks), json_encode($pool->released)));
    exit(2);
}
if ($connections->closed !== [['token-dead'], ['token-dead']]) {
    fwrite(STDERR, sprintf("Unexpected closes %s.\n", json_encode($connections->closed)));
    exit(3);
}
echo "two ticks, each released its coroutine, each closed the dead token\n";
PHP;
        $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 5) . '/vendor/autoload.php']);
        $process->setTimeout(8);

        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput() . $process->getOutput());
        self::assertSame("two ticks, each released its coroutine, each closed the dead token\n", $process->getOutput());
    }
}
