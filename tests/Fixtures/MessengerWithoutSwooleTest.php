<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\UnmanagedFactoryServicePool;
use SwooleBundle\SwooleBundle\Component\Locking\Channel\ChannelMutex;
use SwooleBundle\SwooleBundle\Component\Locking\RecursiveOwner\RecursiveOwnerMutex;
use Symfony\Component\Process\Process;

final class MessengerWithoutSwooleTest extends TestCase
{
    public function testExecutionIdentityWithoutTheExtensionIsOutsideACoroutineInEitherMode(): void
    {
        self::assertFalse(extension_loaded('swoole'));
        $swoole = new Swoole();

        self::assertSame(-1, $swoole->getCoroutineId());
        self::assertSame(-1, $swoole->getRawCoroutineId());

        $swoole->setThreadMode(true);

        self::assertSame(-1, $swoole->getCoroutineId());
        self::assertSame(-1, $swoole->getRawCoroutineId());
    }

    public function testRecursiveMutexOwnershipWithoutTheExtensionSurvivesNestedAcquisition(): void
    {
        self::assertFalse(extension_loaded('swoole'));

        foreach ([false, true] as $threadMode) {
            $swoole = new Swoole();
            $swoole->setThreadMode($threadMode);
            $wrapped = new ChannelMutex();
            $mutex = new RecursiveOwnerMutex($swoole, $wrapped);

            $mutex->acquire();
            $mutex->acquire();
            self::assertTrue($mutex->isAcquired());
            self::assertTrue($wrapped->isAcquired());

            $mutex->release();
            self::assertTrue($mutex->isAcquired());
            self::assertTrue($wrapped->isAcquired());

            $mutex->release();
            self::assertFalse($mutex->isAcquired());
            self::assertFalse($wrapped->isAcquired());
        }
    }

    public function testServicePoolWithoutTheExtensionReusesAndReleasesTheExecutionContext(): void
    {
        self::assertFalse(extension_loaded('swoole'));

        foreach ([false, true] as $threadMode) {
            $swoole = new Swoole();
            $swoole->setThreadMode($threadMode);
            $pool = new UnmanagedFactoryServicePool(
                static fn (): \stdClass => new \stdClass(),
                $swoole,
                new RecursiveOwnerMutex($swoole, new ChannelMutex()),
                instancesLimit: 1,
            );

            $service = $pool->get();
            self::assertSame($service, $pool->get());
            self::assertSame(1, $pool->getAssignedCount());

            $pool->releaseFromCoroutine(-1);
            self::assertSame(0, $pool->getAssignedCount());
            self::assertSame(1, $pool->getFreeCount());
            self::assertSame($service, $pool->get());

            $pool->releaseFromCoroutine(-1);
            self::assertSame([0, 1], [$pool->getAssignedCount(), $pool->getFreeCount()]);
        }
    }

    public function testSwooleIsAbsentInTheWorkerAndItsPhpChild(): void
    {
        $expected = [
            'extension' => false,
            'Swoole\\Server' => false,
            'Swoole\\Http\\Server' => false,
            'Swoole\\Table' => false,
            'Swoole\\Coroutine' => false,
            'swoole_version' => false,
            'swoole_event_wait' => false,
            'Swoole\\Coroutine\\run' => false,
        ];

        self::assertSame($expected, [
            'extension' => extension_loaded('swoole'),
            'Swoole\\Server' => class_exists('Swoole\\Server', false),
            'Swoole\\Http\\Server' => class_exists('Swoole\\Http\\Server', false),
            'Swoole\\Table' => class_exists('Swoole\\Table', false),
            'Swoole\\Coroutine' => class_exists('Swoole\\Coroutine', false),
            'swoole_version' => function_exists('swoole_version'),
            'swoole_event_wait' => function_exists('swoole_event_wait'),
            'Swoole\\Coroutine\\run' => function_exists('Swoole\\Coroutine\\run'),
        ], 'The qualification worker must have no native Swoole runtime.');

        // No CLI ini overrides or environment replacements: children inherit the qualification runtime.
        $child = new Process([PHP_BINARY, '-r', <<<'PHP'
echo json_encode([
    'extension' => extension_loaded('swoole'),
    'Swoole\\Server' => class_exists('Swoole\\Server', false),
    'Swoole\\Http\\Server' => class_exists('Swoole\\Http\\Server', false),
    'Swoole\\Table' => class_exists('Swoole\\Table', false),
    'Swoole\\Coroutine' => class_exists('Swoole\\Coroutine', false),
    'swoole_version' => function_exists('swoole_version'),
    'swoole_event_wait' => function_exists('swoole_event_wait'),
    'Swoole\\Coroutine\\run' => function_exists('Swoole\\Coroutine\\run'),
], JSON_THROW_ON_ERROR);
PHP]);
        $child->setTimeout(10);
        $child->run();

        self::assertSame(0, $child->getExitCode(), $child->getErrorOutput());
        self::assertSame('', $child->getErrorOutput(), 'The nested PHP runtime must start without warnings.');
        self::assertSame($expected, json_decode($child->getOutput(), true, flags: JSON_THROW_ON_ERROR), 'Nested PHP must inherit the absence of Swoole.');
    }
}
