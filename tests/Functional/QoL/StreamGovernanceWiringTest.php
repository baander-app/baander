<?php

declare(strict_types=1);

namespace App\Tests\Functional\QoL;

use App\QoL\Application\Port\StreamAdmissionPortInterface;
use App\QoL\Domain\Port\QualityLadderPortInterface;
use App\QoL\Infrastructure\AllowedQualityTiersService;
use App\QoL\Infrastructure\StreamAdmissionService;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use App\Transcode\Domain\Event\TranscodeJobCompleted;
use App\Transcode\Domain\Event\TranscodeSessionAttached;
use App\Transcode\Infrastructure\QoL\QualityFilteringStreamingDecorator;
use App\Transcode\Infrastructure\QoL\StreamAdmissionListener;
use App\Transcode\Infrastructure\QoL\StreamCompletionListener;
use App\Transcode\Infrastructure\Transcode\CachedTranscodeStreamingService;
use App\Transcode\Infrastructure\Transcode\QualityLadderPort;
use App\Transcode\Infrastructure\Transcode\TranscodeStreamingService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;

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

        $admission = $this->listener($dispatcher, TranscodeSessionAttached::class, StreamAdmissionListener::class);
        self::assertSame(1, $dispatcher->getListenerPriority(TranscodeSessionAttached::class, $admission));
        $completion = $this->listener($dispatcher, TranscodeJobCompleted::class, StreamCompletionListener::class)[0];
        self::assertInstanceOf(CoWrapper::class, (new \ReflectionProperty($completion, 'coWrapper'))->getValue($completion));
        $admissionPort = $container->get(StreamAdmissionPortInterface::class);
        self::assertInstanceOf(StreamAdmissionService::class, $admissionPort);
        self::assertSame($admissionPort, (new \ReflectionProperty($admission[0], 'admission'))->getValue($admission[0]));
        self::assertSame($admissionPort, (new \ReflectionProperty($completion, 'admission'))->getValue($completion));
        self::assertInstanceOf(QualityLadderPort::class, $container->get(QualityLadderPortInterface::class));

        $streaming = $container->get(TranscodeStreamingPortInterface::class);
        self::assertInstanceOf(CachedTranscodeStreamingService::class, $streaming);
        $filtering = (new \ReflectionProperty($streaming, 'inner'))->getValue($streaming);
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

    /**
     * @param class-string $listenerClass
     *
     * @return array{object, string}
     */
    private function listener(EventDispatcherInterface $dispatcher, string $event, string $listenerClass): array
    {
        $matches = array_values(array_filter(
            $dispatcher->getListeners($event),
            static fn (mixed $listener): bool => is_array($listener) && $listener[0] instanceof $listenerClass,
        ));
        self::assertCount(1, $matches, sprintf('%s must be registered once on %s.', $listenerClass, $event));

        return $matches[0];
    }
}
