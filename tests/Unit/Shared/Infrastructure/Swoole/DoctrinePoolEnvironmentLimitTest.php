<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Doctrine\DBAL\DBALPlatformAliveKeeper;
use SwooleBundle\SwooleBundle\Bridge\Doctrine\DoctrineProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\ClassModificationProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\Proxifier;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\StatefulServiceTag;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class DoctrinePoolEnvironmentLimitTest extends TestCase
{
    public function testPoolLimitKeepsItsEnvironmentReferenceUntilRuntime(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['DoctrineBundle' => 'DoctrineBundle']);
        $container->setParameter('doctrine.entity_managers', []);
        $container->setParameter('doctrine.connections', ['default' => 'connection']);
        $container->register('doctrine', \stdClass::class);
        $container->register('connection', \stdClass::class);
        $container->register('doctrine.dbal.default_connection.event_manager', \stdClass::class);
        $container->register(DBALPlatformAliveKeeper::class)->setArgument(1, []);
        $limit = $container->getParameterBag()->resolveValue('%env(int:BAANDER_POOL_LIMIT)%');

        $processor = new DoctrineProcessor(['global_limit' => $limit]);
        $processor->process($container, new Proxifier($container, new ClassModificationProcessor($container)));

        foreach (['connection', 'doctrine.dbal.default_connection.event_manager'] as $service) {
            $tags = $container->getDefinition($service)->getTag(ContainerConstants::TAG_STATEFUL_SERVICE);
            self::assertSame($limit, new StatefulServiceTag($tags[0])->getLimit());
        }
    }
}
