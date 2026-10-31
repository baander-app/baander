<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Tests\Fixtures\Swoole\CoroutineProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FirstTimeOnlyMutexRuntimeTest extends TestCase
{
    /** @return iterable<string, array{int, bool}> */
    public static function callers(): iterable
    {
        yield 'no waiters' => [0, false];
        yield 'several waiters' => [3, false];
        yield 'failed initializer' => [3, true];
    }

    #[DataProvider('callers')]
    public function testEachCallerReleasesOnlyTheMutexItAcquired(int $waiters, bool $failInitialization): void
    {
        $script = <<<'PHP'
require $argv[1];
$underlying = new SwooleBundle\SwooleBundle\Component\Locking\RecursiveOwner\RecursiveOwnerMutex(
    new SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole(),
    new SwooleBundle\SwooleBundle\Component\Locking\Channel\ChannelMutex(acquireTimeout: .25),
);
$mutex = new SwooleBundle\SwooleBundle\Component\Locking\FirstTimeOnly\FirstTimeOnlyMutex($underlying);
$completed = 0;
$errors = [];
Swoole\Coroutine\run(static function () use ($argv, $mutex, &$completed, &$errors): void {
    $mutex->acquire();
    for ($i = 0; $i < (int) $argv[2]; ++$i) {
        Swoole\Coroutine::create(static function () use ($mutex, &$completed, &$errors): void {
            try {
                $mutex->acquire();
                ++$completed;
                $mutex->release();
            } catch (Throwable $error) {
                $errors[] = $error->getMessage();
            }
        });
    }
    try {
        try {
            if ($argv[3] === '1') {
                throw new RuntimeException('initialization failed');
            }
        } finally {
            $mutex->release();
        }
    } catch (Throwable $error) {
        if ($error->getMessage() !== 'initialization failed') {
            $errors[] = $error->getMessage();
        }
    }
    try {
        $mutex->acquire();
        $mutex->release();
    } catch (Throwable $error) {
        $errors[] = $error->getMessage();
    }
});
echo json_encode(['completed' => $completed, 'errors' => $errors, 'acquired' => $underlying->isAcquired()], JSON_THROW_ON_ERROR);
PHP;
        $process = CoroutineProbe::create(['-r', $script, dirname(__DIR__, 5) . '/vendor/autoload.php', (string) $waiters, $failInitialization ? '1' : '0']);
        $process->setTimeout(5);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
        self::assertSame(['completed' => $waiters, 'errors' => [], 'acquired' => false], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }
}
