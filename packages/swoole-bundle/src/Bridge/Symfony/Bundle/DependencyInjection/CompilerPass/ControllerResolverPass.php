<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass;

use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpKernel\Controller\NonMutatingControllerResolver;
use Symfony\Bundle\FrameworkBundle\Controller\ControllerResolver;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ControllerResolverPass implements CompilerPassInterface
{
    private const string SERVICE_ID = 'controller_resolver';

    public function process(ContainerBuilder $container): void
    {
        if (!$this->areCoroutinesEnabled($container) || !$container->hasDefinition(self::SERVICE_ID)) {
            return;
        }

        $definition = $container->getDefinition(self::SERVICE_ID);

        if ($definition->getClass() !== ControllerResolver::class) {
            return;
        }

        $definition->setClass(NonMutatingControllerResolver::class);
    }

    private function areCoroutinesEnabled(ContainerBuilder $container): bool
    {
        return $container->hasParameter(ContainerConstants::PARAM_COROUTINES_ENABLED)
            && $container->getParameter(ContainerConstants::PARAM_COROUTINES_ENABLED) === true;
    }
}
