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

        self::assertSame([], $dispatcher->getListeners(TranscodeSessionAttached::class));
        self::assertSame([], $dispatcher->getListeners(TranscodeJobCompleted::class));
        self::assertInstanceOf(StreamAdmissionService::class, $container->get(StreamAdmissionPortInterface::class));
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

}
