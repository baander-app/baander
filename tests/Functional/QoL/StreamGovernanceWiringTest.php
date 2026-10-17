<?php

declare(strict_types=1);

namespace App\Tests\Functional\QoL;

use App\QoL\Application\Port\StreamAdmissionPortInterface;
use App\QoL\Domain\Port\QualityLadderPortInterface;
use App\QoL\Infrastructure\AllowedQualityTiersService;
use App\QoL\Infrastructure\StreamAdmissionService;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPoolInterface;
use App\Shared\Domain\Event\Outbox\OutboxSubscriber;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Infrastructure\Swoole\TranscodeJobEventSubscriber;
use App\Transcode\Infrastructure\Swoole\TranscodeSessionSubscriber;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use App\Transcode\Domain\Event\TranscodeJobCompleted;
use App\Transcode\Domain\Event\TranscodeSessionAttached;
use App\Transcode\Infrastructure\QoL\QualityFilteringStreamingDecorator;
use App\Transcode\Infrastructure\Transcode\QualityLadderPort;
use App\Transcode\Infrastructure\Transcode\TranscodeStreamingService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class StreamGovernanceWiringTest extends KernelTestCase
{
    public function testCompiledContainerWiresAdmissionCompletionAndManifestFiltering(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $attachmentListeners = $this->listenerClasses($dispatcher, TranscodeSessionAttached::class);
        $completionListeners = $this->listenerClasses($dispatcher, TranscodeJobCompleted::class);
        self::assertNotContains('App\\Transcode\\Infrastructure\\QoL\\StreamAdmissionListener', $attachmentListeners);
        self::assertNotContains(TranscodeSessionSubscriber::class, $attachmentListeners);
        self::assertNotContains('App\\Transcode\\Infrastructure\\QoL\\StreamCompletionListener', $completionListeners);
        self::assertContains(TranscodeJobEventSubscriber::class, $completionListeners);
        self::assertContains(OutboxSubscriber::class, $completionListeners);
        $admission = $container->get(StreamAdmissionPortInterface::class);
        self::assertInstanceOf(StreamAdmissionService::class, $admission);
        $container->set('worker.media.cpu_pool', $this->createStub(CpuProcessPoolInterface::class));
        $container->set('worker.media.encoder_profile', EncoderProfile::software());
        $executor = $container->get('transcode.loop_starter');
        self::assertInstanceOf(TranscodeSessionSubscriber::class, $executor);
        self::assertSame($admission, (new \ReflectionProperty($executor, 'admission'))->getValue($executor));
        self::assertInstanceOf(QualityLadderPort::class, $container->get(QualityLadderPortInterface::class));

        $streaming = $container->get(TranscodeStreamingPortInterface::class);
        $filtering = $streaming;
        self::assertInstanceOf(QualityFilteringStreamingDecorator::class, $filtering);
        self::assertInstanceOf(
            AllowedQualityTiersService::class,
            (new \ReflectionProperty($filtering, 'allowedTiers'))->getValue($filtering),
        );
        self::assertInstanceOf(
            TranscodeStreamingService::class,
            (new \ReflectionProperty($filtering, 'inner'))->getValue($filtering),
        );
    }

    /** @return list<class-string> */
    private function listenerClasses(EventDispatcherInterface $dispatcher, string $event): array
    {
        $classes = [];
        foreach ($dispatcher->getListeners($event) as $listener) {
            if (is_array($listener) && is_object($listener[0])) {
                $classes[] = $listener[0]::class;
            } elseif (is_object($listener)) {
                $classes[] = $listener::class;
            }
        }

        return $classes;
    }
}
