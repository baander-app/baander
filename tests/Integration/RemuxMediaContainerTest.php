<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\QoL\Infrastructure\Worker\WorkerQoLLifecycle;
use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Infrastructure\Worker\NativeExecutionClientInterface;
use App\Transcode\Application\Port\EncodePresentationPort;
use App\Transcode\Application\Port\TranscodeLoopStarterInterface;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use App\Transcode\Infrastructure\Swoole\TranscodeSessionSubscriber;
use App\Transcode\Infrastructure\Swoole\TranscodeStreamManager;
use App\Transcode\Infrastructure\Transcode\AudioRenditionExecutor;
use App\Transcode\Infrastructure\Transcode\EncodePresentationAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;

final class RemuxMediaContainerTest extends TestCase
{
    public function testCopyWorkerServicesResolveWithoutAnEncoderProfile(): void
    {
        $directory = sys_get_temp_dir() . '/baander-remux-container-' . bin2hex(random_bytes(8));
        $savedEnv = $_ENV;
        $savedServer = $_SERVER;
        $_ENV['BAANDER_DEPLOYMENT_PROFILE'] = $_SERVER['BAANDER_DEPLOYMENT_PROFILE'] = (new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2))->toJson();
        $_ENV['CONVERT_STORAGE_PATH'] = $_SERVER['CONVERT_STORAGE_PATH'] = $directory . '/convert';
        $kernel = new RemuxMediaKernel($directory);

        try {
            $kernel->boot();
            $container = $kernel->getContainer();
            $native = $this->createMock(NativeExecutionClientInterface::class);
            $native->expects(self::never())->method('submit');
            $container->set(NativeExecutionClientInterface::class, $native);

            // Copy children do not receive the synthetic encoder service at startup.
            self::assertFalse($container->initialized('worker.media.encoder_profile'));
            self::assertInstanceOf(TranscodeLoopStarterInterface::class, $container->get('transcode.loop_starter'));
            self::assertInstanceOf(WorkerQoLLifecycle::class, $container->get('worker.media.qol_lifecycle'));
            self::assertInstanceOf(AudioRenditionExecutor::class, $container->get('worker.media.audio_executor'));
            self::assertInstanceOf(EncodePresentationPort::class, $container->get(EncodePresentationAdapter::class));
            self::assertFalse($container->initialized('worker.media.encoder_profile'));
            self::assertFalse($container->initialized(SegmentEncoder::class));

            $profile = EncoderProfile::software();
            $container->set('worker.media.encoder_profile', $profile);
            $encoder = null;
            foreach ([TranscodeSessionSubscriber::class, TranscodeStreamManager::class] as $consumer) {
                $service = $consumer === TranscodeSessionSubscriber::class
                    ? $container->get('transcode.loop_starter') : $container->get($consumer);
                $resolved = new \ReflectionMethod($consumer, 'encoder')->invoke($service);
                self::assertInstanceOf(SegmentEncoder::class, $resolved);
                self::assertSame($profile, $resolved->getEncoderProfile());
                $encoder ??= $resolved;
                self::assertSame($encoder, $resolved);
            }
            self::assertTrue($container->initialized(SegmentEncoder::class));
        } finally {
            $kernel->shutdown();
            $_ENV = $savedEnv;
            $_SERVER = $savedServer;
            new Filesystem()->remove($directory);
        }
    }
}

final class RemuxMediaKernel extends Kernel
{
    public function __construct(private readonly string $temporaryDirectory)
    {
        parent::__construct('prod', false);
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition(EncodePresentationAdapter::class)->setPublic(true);
                $container->getDefinition(SegmentEncoder::class)->setPublic(true);
                $container->getDefinition(TranscodeStreamManager::class)->setPublic(true);
            }
        });
    }

    public function getCacheDir(): string
    {
        return $this->temporaryDirectory . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->temporaryDirectory . '/log';
    }
}
