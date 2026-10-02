<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Metadata\Application\Message\SyncAlbumMessage;
use App\Metadata\Application\Message\SyncLibraryMessage;
use App\Metadata\Application\Message\SyncSongMessage;
use App\Metadata\Application\MessageHandler\SyncAlbumHandler;
use App\Metadata\Application\MessageHandler\SyncLibraryHandler;
use App\Metadata\Application\MessageHandler\SyncSongHandler;
use App\Radio\Application\Command\SyncCountryStationsCommand;
use App\Radio\Application\CommandHandler\SyncCountryStationsHandler;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\DependencyInjection\MessengerPass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class SwooleFallbackHandlerRegistrationTest extends TestCase
{
    #[DataProvider('handlerTransports')]
    public function testRealHandlerAttributesSelectOnlySupportedDeliveryTransports(
        string $handlerClass,
        object $message,
        ?string $transport,
        int $expectedCount,
    ): void {
        $locator = $this->compileLocator($handlerClass);
        $handlers = iterator_to_array($locator->getHandlers(new Envelope($message, $transport === null ? [] : [new ReceivedStamp($transport)])));

        self::assertCount($expectedCount, $handlers, $handlerClass . ' received from ' . ($transport ?? 'synchronous dispatch'));
        if ($expectedCount === 1) {
            self::assertSame($handlerClass . '::__invoke', $handlers[0]->getName());
        }
    }

    public static function handlerTransports(): iterable
    {
        $messages = [
            SyncSongHandler::class => new SyncSongMessage(Uuid::v7()),
            SyncAlbumHandler::class => new SyncAlbumMessage(Uuid::v7()),
            SyncLibraryHandler::class => new SyncLibraryMessage(Uuid::v7()),
            SyncCountryStationsHandler::class => new SyncCountryStationsCommand(Uuid::v7(), 'DK'),
        ];
        foreach ($messages as $handler => $message) {
            foreach ([['async', 1], ['swoole_task', 1], ['unrelated', 0], [null, 1]] as [$transport, $expectedCount]) {
                yield $handler . ' on ' . ($transport ?? 'synchronous dispatch') => [$handler, $message, $transport, $expectedCount];
            }
        }
    }

    private function compileLocator(string $handlerClass): HandlersLocatorInterface
    {
        // Obtain Symfony's own attribute configurator rather than recreating handler tags.
        $framework = new ContainerBuilder();
        $framework->setParameter('kernel.debug', false);
        $framework->setParameter('kernel.container_class', 'HandlerRegistrationTestContainer');
        $framework->setParameter('kernel.environment', 'test');
        $framework->setParameter('kernel.cache_dir', sys_get_temp_dir() . '/baander-handler-registration');
        $framework->setParameter('kernel.build_dir', sys_get_temp_dir() . '/baander-handler-registration');
        $framework->setParameter('kernel.logs_dir', sys_get_temp_dir() . '/baander-handler-registration');
        $framework->setParameter('kernel.bundles_metadata', []);
        $framework->setParameter('kernel.bundles', []);
        $framework->setParameter('kernel.project_dir', dirname(__DIR__, 5));
        (new FrameworkExtension())->load([['secret' => 'registration-test']], $framework);

        $container = new ContainerBuilder();
        foreach ($framework->getAttributeAutoconfigurators()[AsMessageHandler::class] as $configurator) {
            $container->registerAttributeForAutoconfiguration(AsMessageHandler::class, $configurator);
        }
        $container->register('test.bus', MessageBus::class)->addTag('messenger.bus');
        $container->register($handlerClass, $handlerClass)->setSynthetic(true)->setPublic(true)->setAutoconfigured(true);
        $container->setAlias('test.handlers', 'test.bus.messenger.handlers_locator')->setPublic(true);
        $container->addCompilerPass(new MessengerPass());
        $container->compile();
        // Selection needs a callable, but must not execute application work or its dependencies.
        $container->set($handlerClass, (new \ReflectionClass($handlerClass))->newInstanceWithoutConstructor());

        return $container->get('test.handlers');
    }
}
