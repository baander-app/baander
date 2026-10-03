<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Transcode\Application\Exception\TranscodeStartupUnavailableException;
use App\Transcode\Interface\EventListener\TranscodeStartupUnavailableListener;
use Nelmio\ApiDocBundle\Render\RenderOpenApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/** Qualifies production listener wiring and actual Messenger wrapping without external I/O. */
final class TranscodeStartupUnavailableTest extends TestCase
{
    public function testProductionDispatcherMapsActualMessageBusStartupFailure(): void
    {
        $directory = sys_get_temp_dir() . '/baander-transcode-startup-' . bin2hex(random_bytes(8));
        $kernel = new TranscodeStartupUnavailableKernel($directory);
        try {
            $kernel->boot();
            $container = $kernel->getContainer();
            $listener = $container->get('transcode.startup.listener');
            self::assertInstanceOf(TranscodeStartupUnavailableListener::class, $listener);
            $dispatcher = $container->get('event_dispatcher');
            self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
            self::assertContains([$listener, '__invoke'], $dispatcher->getListeners(KernelEvents::EXCEPTION));
            self::assertSame(20, $dispatcher->getListenerPriority(KernelEvents::EXCEPTION, [$listener, '__invoke']));
            $renderer = $container->get('transcode.startup.openapi');
            self::assertInstanceOf(RenderOpenApi::class, $renderer);
            $spec = json_decode($renderer->render('json', 'default'), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($spec);
            foreach (['/api/transcode/sessions/', '/api/stream/sign'] as $path) {
                $responseSchema = $spec['paths'][$path]['post']['responses']['503'];
                self::assertSame('integer', $responseSchema['headers']['Retry-After']['schema']['type']);
                self::assertSame(2, $responseSchema['headers']['Retry-After']['schema']['example']);
                $schema = $responseSchema['content']['application/json']['schema'];
                self::assertSame('object', $schema['type']);
                self::assertContains('error', $schema['required']);
                self::assertSame('#/components/schemas/ApiError', $schema['properties']['error']['$ref']);
            }
            self::assertSame('string', $spec['components']['schemas']['ApiError']['properties']['message']['type']);
            self::assertSame('integer', $spec['components']['schemas']['ApiError']['properties']['code']['type']);
            $bus = $container->get('transcode.startup.bus');
            self::assertInstanceOf(MessageBusInterface::class, $bus);
            try {
                $bus->dispatch(new TranscodeStartupUnavailableProbe());
                self::fail('The real message bus must wrap the startup refusal.');
            } catch (HandlerFailedException $failure) {
                self::assertCount(1, $failure->getWrappedExceptions(TranscodeStartupUnavailableException::class));
                $event = new ExceptionEvent($kernel, Request::create('https://baander.app/api/stream/sign', 'POST'),
                    HttpKernelInterface::MAIN_REQUEST, $failure);
                $dispatcher->dispatch($event, KernelEvents::EXCEPTION);
                $response = $event->getResponse();
                self::assertNotNull($response);
                self::assertSame(503, $response->getStatusCode());
                self::assertSame('2', $response->headers->get('Retry-After'));
                self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
                self::assertFalse($response->headers->hasCacheControlDirective('public'));
                self::assertSame(['error' => ['message' => 'Transcode startup is temporarily unavailable. Please retry.', 'code' => 503]],
                    json_decode($response->getContent() ?: '', true, 32, JSON_THROW_ON_ERROR));
            }
        } finally {
            $kernel->shutdown();
            (new Filesystem())->remove($directory);
        }
    }
}

final class TranscodeStartupUnavailableProbe
{
}

final class TranscodeStartupUnavailableProbeHandler
{
    public function __invoke(TranscodeStartupUnavailableProbe $message): void
    {
        throw new TranscodeStartupUnavailableException();
    }
}

final class TranscodeStartupUnavailableKernel extends Kernel
{
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
        $container->setAlias('transcode.startup.listener', TranscodeStartupUnavailableListener::class)->setPublic(true);
        $container->setAlias('transcode.startup.bus', 'messenger.bus.default')->setPublic(true);
        $container->setAlias('transcode.startup.openapi', RenderOpenApi::class)->setPublic(true);
        $container->setDefinition(TranscodeStartupUnavailableProbeHandler::class,
            (new Definition(TranscodeStartupUnavailableProbeHandler::class))->addTag('messenger.message_handler', [
                'bus' => 'messenger.bus.default',
            ]));
    }
}
