<?php

declare(strict_types=1);

namespace App\Tests\Functional\QoL;

use App\QoL\Application\Port\EncoderProfileFingerprintPortInterface;
use App\QoL\Infrastructure\Swoole\LearningDataPersister;
use App\QoL\Infrastructure\Swoole\QoLWorkerStartupSubscriber;
use App\Shared\Infrastructure\Swoole\SwooleWorkerEventSubscriber;
use App\Transcode\Infrastructure\FFmpeg\EncoderProfileFingerprintAdapter;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class QoLWorkerStartupWiringTest extends KernelTestCase
{
    public function testCompiledContainerRegistersIndependentStartupSubscribersAndFingerprintAdapter(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $listenerClasses = [];
        foreach ($dispatcher->getListeners(WorkerStartedEvent::NAME) as $listener) {
            if (is_array($listener) && is_object($listener[0])) {
                $listenerClasses[] = $listener[0]::class;
            }
        }

        self::assertContains(QoLWorkerStartupSubscriber::class, $listenerClasses);
        self::assertContains(SwooleWorkerEventSubscriber::class, $listenerClasses);
        $fingerprint = $container->get(EncoderProfileFingerprintPortInterface::class);
        self::assertInstanceOf(EncoderProfileFingerprintAdapter::class, $fingerprint);
        $persister = $container->get(LearningDataPersister::class);
        self::assertSame($fingerprint, (new \ReflectionProperty($persister, 'fingerprint'))->getValue($persister));
    }
}
