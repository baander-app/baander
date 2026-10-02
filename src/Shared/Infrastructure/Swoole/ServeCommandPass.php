<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\Command\ServerRunCommand;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Names the existing foreground server command without changing its boot lifecycle. */
final class ServeCommandPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $definition = $container->getDefinition(ServerRunCommand::class);
        $tags = $definition->getTag('console.command');
        if ($tags === []) {
            throw new \LogicException('The foreground Swoole server command must be registered.');
        }

        $tags[0]['command'] = 'app:serve|swoole:server:run';
        $definition->clearTag('console.command');
        foreach ($tags as $tag) {
            $definition->addTag('console.command', $tag);
        }
    }
}
