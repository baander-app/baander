<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpKernel\Controller\NonMutatingControllerResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\ControllerResolverPass;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use Symfony\Bundle\FrameworkBundle\Controller\ControllerResolver;
use Symfony\Component\HttpFoundation\Request;

final class ControllerResolutionIsolationTest extends TestCase
{
    public function testResolverReplacementPreservesFrameworkArgumentsAndAllowedControllers(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_ENABLED, true);
        $definition = (new Definition(ControllerResolver::class, ['container', 'logger']))
            ->addMethodCall('allowControllers', [[ControllerResolutionFixture::class]])
            ->addTag('container.service_subscriber');
        $container->setDefinition('controller_resolver', $definition);
        (new ControllerResolverPass())->process($container);
        self::assertSame(NonMutatingControllerResolver::class, $definition->getClass());
        self::assertSame(['container', 'logger'], $definition->getArguments());
        self::assertSame([['allowControllers', [[ControllerResolutionFixture::class]]]], $definition->getMethodCalls());
        self::assertTrue($definition->hasTag('container.service_subscriber'));
    }

    public function testResolverReplacementIsDisabledWithoutCoroutinesAndRespectsCustomResolvers(): void
    {
        $container = new ContainerBuilder();
        $definition = new Definition(ControllerResolver::class);
        $container->setDefinition('controller_resolver', $definition);
        $pass = new ControllerResolverPass();
        $pass->process($container);
        self::assertSame(ControllerResolver::class, $definition->getClass());
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_ENABLED, true);
        $definition->setClass('Baander\\CustomControllerResolver');
        $pass->process($container);
        self::assertSame('Baander\\CustomControllerResolver', $definition->getClass());
    }

    public function testResolvingSharedControllerDoesNotWriteItsContainer(): void
    {
        $controller = new ControllerResolutionFixture();
        $controller->setContainer(new Container());
        $container = new Container();
        $container->set(ControllerResolutionFixture::class, $controller);
        $resolver = new NonMutatingControllerResolver($container);
        $request = new Request(attributes: ['_controller' => ControllerResolutionFixture::class . '::action']);
        self::assertSame([$controller, 'action'], $this->resolveController($resolver, $request));
        self::assertSame([$controller, 'action'], $this->resolveController($resolver, $request));
        self::assertSame(1, $controller->containerWrites);
    }

    /** @phpstan-impure */
    private function resolveController(NonMutatingControllerResolver $resolver, Request $request): callable|false
    {
        return $resolver->getController($request);
    }

    public function testUnconfiguredAbstractControllerStillFails(): void
    {
        $container = new Container();
        $container->set(ControllerResolutionFixture::class, new ControllerResolutionFixture());
        $resolver = new NonMutatingControllerResolver($container);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has no container set');
        $resolver->getController(new Request(attributes: ['_controller' => ControllerResolutionFixture::class . '::action']));
    }
}

final class ControllerResolutionFixture extends AbstractController
{
    public int $containerWrites = 0;

    public function setContainer(ContainerInterface $container): ?ContainerInterface
    {
        ++$this->containerWrites;
        return parent::setContainer($container);
    }

    public function action(): void {}
}
