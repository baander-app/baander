<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\ServeCommandPass;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\Command\ServerRunCommand;
use Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ServeCommandPassTest extends TestCase
{
    public function testBothNamesResolveToTheExistingServerService(): void
    {
        $container = new ContainerBuilder();
        $definition = $container->register(ServerRunCommand::class, ServerRunCommand::class)
            ->addTag('console.command', ['command' => 'swoole:server:run', 'description' => 'Run server.'])
            ->addTag('existing.tag');

        (new ServeCommandPass())->process($container);
        (new ServeCommandPass())->process($container);
        self::assertSame($definition, $container->getDefinition(ServerRunCommand::class));
        self::assertTrue($definition->hasTag('existing.tag'));
        self::assertSame([['command' => 'app:serve|swoole:server:run', 'description' => 'Run server.']], $definition->getTag('console.command'));

        // Exercise the installed Symfony alias parser, rather than merely testing our tag string.
        (new AddConsoleCommandPass())->process($container);
        $map = $container->getDefinition('console.command_loader')->getArgument(1);
        self::assertSame(ServerRunCommand::class, $map['app:serve']);
        self::assertSame($map['app:serve'], $map['swoole:server:run']);
    }

    public function testMissingCommandRegistrationFailsClosed(): void
    {
        $container = new ContainerBuilder();
        $container->register(ServerRunCommand::class, ServerRunCommand::class);
        $this->expectException(\LogicException::class);
        (new ServeCommandPass())->process($container);
    }
}
