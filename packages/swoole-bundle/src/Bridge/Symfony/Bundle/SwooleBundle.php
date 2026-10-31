<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle;

use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\{
    ControllerResolverPass,
    ExceptionHandlerPass,
    FinalizeDefinitionsAfterRemovalPass,
    MessengerTransportFactoryPass,
    RouterOptimizerPass,
    SessionStorageListenerPass,
    StatefulServicesPass,
    StreamedResponseListenerPass,
};
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use SwooleBundle\SwooleBundle\Bridge\Symfony\ErrorHandler\ContextualErrorHandler;

final class SwooleBundle extends Bundle
{
    public function boot(): void
    {
        if ($this->container?->hasParameter(ContainerConstants::PARAM_COROUTINES_ENABLED)
            && $this->container->getParameter(ContainerConstants::PARAM_COROUTINES_ENABLED)
        ) {
            ContextualErrorHandler::register(new Swoole());
        }
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new ControllerResolverPass());
        $container->addCompilerPass(new RouterOptimizerPass());
        $container->addCompilerPass(new StreamedResponseListenerPass());
        $container->addCompilerPass(new SessionStorageListenerPass());
        $container->addCompilerPass(new MessengerTransportFactoryPass());
        $container->addCompilerPass(new ExceptionHandlerPass());
        $container->addCompilerPass(new StatefulServicesPass(), PassConfig::TYPE_BEFORE_REMOVING, -10000);
        $container->addCompilerPass(new FinalizeDefinitionsAfterRemovalPass(), PassConfig::TYPE_AFTER_REMOVING, -10000);
    }
}
