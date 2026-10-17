<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Kernel;
use App\QoL\Infrastructure\Swoole\CpuGpuSampler;
use App\QoL\Infrastructure\Swoole\QoLWorkerStartupSubscriber;
use App\Shared\Application\Port\ServerControlPortInterface;
use App\Shared\Infrastructure\Health\HealthMonitorSubscriber;
use App\Shared\Infrastructure\Swoole\Control\Operation\DebugWorkersOperation;
use App\Shared\Infrastructure\Swoole\Control\Operation\WebSocketUserDisconnectOperation;
use App\Shared\Infrastructure\Swoole\Control\ServerControlOperationRegistry;
use App\Shared\Infrastructure\Swoole\Drain\DrainCoordinator;
use App\Shared\Infrastructure\Swoole\Drain\DrainShutdownHandler;
use App\Shared\Infrastructure\Swoole\Drain\DrainWorkerExitSubscriber;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use App\Shared\Infrastructure\Swoole\SwooleWorkerEventSubscriber;
use App\Transcode\Application\CommandHandler\CreateTranscodeSessionHandler;
use App\Transcode\Application\CommandHandler\UpdateTranscodePositionHandler;
use App\Transcode\Application\Port\AudioRenditionPortInterface;
use App\Transcode\Application\Port\PlaybackPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use App\Transcode\Infrastructure\FFmpeg\HardwareCapabilitiesProber;
use App\Transcode\Infrastructure\Swoole\TranscodeSessionSubscriber;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\ServerStartedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerExitedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStoppedEvent;
use SwooleBundle\SwooleBundle\Server\HttpServerConfiguration;
use SwooleBundle\SwooleBundle\Server\LifecycleHandler\ServerBeforeShutdownHandler;
use SwooleBundle\SwooleBundle\Server\LifecycleHandler\ServerShutdownHandler;
use SwooleBundle\SwooleBundle\Server\Runtime\CallableBootManager;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WebExecutionOwnershipTest extends TestCase
{
    public function testProductionWebServicesAndLifecycleNeedNoWorkerExecutor(): void
    {
        // Compiling and instrumenting the production coroutine container exceeds PHP's 128 MB default.
        ini_set('memory_limit', '512M');
        $directory = sys_get_temp_dir() . '/baander-web-ownership-' . bin2hex(random_bytes(8));
        $kernel = new WebExecutionOwnershipKernel($directory);
        try {
            $kernel->boot();
            $container = $kernel->getContainer();
            self::assertFalse($container->initialized('worker.media.cpu_pool'));
            self::assertFalse($container->initialized('worker.media.encoder_profile'));

            foreach (WebExecutionOwnershipKernel::WEB_SERVICES as $port) {
                self::assertInstanceOf($port, $container->get('web.ownership.' . $port));
            }

            $configuration = $container->get('web.ownership.configuration');
            self::assertInstanceOf(HttpServerConfiguration::class, $configuration);
            self::assertSame(0, $configuration->getTaskWorkerCount());
            self::assertInstanceOf(DrainCoordinator::class, $container->get('web.ownership.before_shutdown'));
            self::assertInstanceOf(DrainShutdownHandler::class, $container->get('web.ownership.shutdown'));

            $bootManager = $container->get('web.ownership.boot_manager');
            self::assertInstanceOf(CallableBootManager::class, $bootManager);
            $bootables = (new \ReflectionProperty($bootManager, 'bootables'))->getValue($bootManager);
            $bootableClasses = [];
            foreach ($bootables as $callback) {
                $service = (new \ReflectionFunction($callback))->getClosureThis();
                self::assertNotNull($service);
                $bootableClasses[] = $service::class;
            }
            self::assertNotEmpty($bootableClasses);
            self::assertNotContains(CpuProcessPool::class, $bootableClasses);
            self::assertNotContains(HardwareCapabilitiesProber::class, $bootableClasses);
            self::assertNotContains(CpuGpuSampler::class, $bootableClasses);

            $dispatcher = $container->get('event_dispatcher');
            self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
            $listeners = [];
            foreach ([ServerStartedEvent::NAME, WorkerStartedEvent::NAME, WorkerStoppedEvent::NAME, WorkerExitedEvent::NAME] as $event) {
                foreach ($dispatcher->getListeners($event) as $listener) {
                    if (is_array($listener) && is_object($listener[0])) {
                        $listeners[$event][] = $listener[0]::class;
                    }
                }
            }
            self::assertContains(SwooleWorkerEventSubscriber::class, $listeners[ServerStartedEvent::NAME]);
            self::assertContains(HealthMonitorSubscriber::class, $listeners[WorkerStartedEvent::NAME]);
            self::assertContains(HealthMonitorSubscriber::class, $listeners[WorkerStoppedEvent::NAME]);
            self::assertContains(DrainWorkerExitSubscriber::class, $listeners[WorkerExitedEvent::NAME]);
            foreach ($listeners as $classes) {
                self::assertNotContains(TranscodeSessionSubscriber::class, $classes);
                self::assertNotContains(QoLWorkerStartupSubscriber::class, $classes);
            }

            $operations = $container->get('web.ownership.operations');
            self::assertInstanceOf(ServerControlOperationRegistry::class, $operations);
            self::assertInstanceOf(DebugWorkersOperation::class, $operations->get(DebugWorkersOperation::NAME));
            self::assertInstanceOf(WebSocketUserDisconnectOperation::class, $operations->get('websocket.user.disconnect'));
            self::assertFalse($container->initialized('worker.media.cpu_pool'));
            self::assertFalse($container->initialized('worker.media.encoder_profile'));
        } finally {
            $kernel->shutdown();
            (new Filesystem())->remove($directory);
        }
    }
}

final class WebExecutionOwnershipKernel extends Kernel
{
    public const array WEB_SERVICES = [
        CreateTranscodeSessionHandler::class,
        UpdateTranscodePositionHandler::class,
        PlaybackPortInterface::class,
        TranscodeSessionPortInterface::class,
        TranscodeStoragePortInterface::class,
        TranscodeStreamingPortInterface::class,
        AudioRenditionPortInterface::class,
        ServerControlPortInterface::class,
    ];

    public function __construct(private readonly string $temporaryDirectory)
    {
        parent::__construct('prod', false);
    }

    public function getProjectDir(): string { return dirname(__DIR__, 2); }
    public function getCacheDir(): string { return $this->temporaryDirectory . '/cache'; }
    public function getLogDir(): string { return $this->temporaryDirectory . '/log'; }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        foreach (self::WEB_SERVICES as $port) {
            $container->setAlias('web.ownership.' . $port, $port)->setPublic(true);
        }
        foreach ([
            'configuration' => HttpServerConfiguration::class,
            'boot_manager' => CallableBootManager::class,
            'before_shutdown' => ServerBeforeShutdownHandler::class,
            'shutdown' => ServerShutdownHandler::class,
            'operations' => ServerControlOperationRegistry::class,
        ] as $name => $service) {
            $container->setAlias('web.ownership.' . $name, $service)->setPublic(true);
        }
    }
}
