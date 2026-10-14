<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Shared\Infrastructure\Security\WsQueryTokenAuthenticator;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Exposes the production WebSocket authenticator without replacing its dependencies. */
final class SecuritySurfaceOAuthKernel extends ProductionOAuthKernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('oauth.acceptance.websocket', WsQueryTokenAuthenticator::class)->setPublic(true);
        $container->setAlias('oauth.acceptance.auth_logger', 'monolog.logger.auth')->setPublic(true);
    }
}
