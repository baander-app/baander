<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The production OAuth kernel for two requests that overlap in one process: RequestSuspension
 * holds a marked request between the firewall and its controller, and the bundle's coroutine
 * starter is exposed, so each request runs in a coroutine that releases its pooled services
 * when it ends, as a request of the web server does.
 */
final class SuspendedRequestOAuthKernel extends ProductionOAuthKernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('oauth.acceptance.co_wrapper', CoWrapper::class)->setPublic(true);
        $container->register(RequestSuspension::class)
            ->setPublic(true)
            ->addTag('kernel.event_listener', [
                'event' => KernelEvents::REQUEST,
                'method' => '__invoke',
                'priority' => RequestSuspension::PRIORITY,
            ]);
    }
}
