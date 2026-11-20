<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Logging;

use App\Shared\Infrastructure\Logging\TerminalLogGuardPass;
use App\Shared\Infrastructure\Logging\TerminalLogGuard;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class TerminalLogGuardPassTest extends TestCase
{
    public function testInheritedMonologChannelsReachTheCompiledTerminalGuard(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('logger.class', Logger::class);
        $container->register('monolog.logger_prototype', '%logger.class%')->setAbstract(true)->setArguments(['app']);
        $container->register('terminal.handler', StreamHandler::class)->setArguments(['php://stderr']);
        foreach (['monolog.logger', 'monolog.logger.request'] as $id) {
            $container->setDefinition($id, new ChildDefinition('monolog.logger_prototype'))
                ->setPublic(true)->addMethodCall('pushHandler', [new Reference('terminal.handler')]);
        }
        $container->register(TerminalLogGuard::class)->setPublic(true)
            ->setArguments([new TaggedIteratorArgument('app.log_viewer.logger')]);
        $container->addCompilerPass(new TerminalLogGuardPass());
        $container->compile();

        $guard = $container->get(TerminalLogGuard::class);
        $guard->begin();
        foreach (['monolog.logger', 'monolog.logger.request'] as $id) {
            self::assertSame([], $container->get($id)->getHandlers());
        }
        $guard->end();
        foreach (['monolog.logger', 'monolog.logger.request'] as $id) {
            self::assertCount(1, $container->get($id)->getHandlers());
        }
    }
}
