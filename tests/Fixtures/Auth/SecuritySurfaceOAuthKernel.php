<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Auth\Application\Port\WebSocketTicketIssuerInterface;
use App\Auth\Application\Port\WebSocketTicketRedeemerInterface;
use App\Auth\Application\Port\WebSocketTicketStoreInterface;
use App\Auth\Application\Port\WebSocketTokenStatusInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Exposes production ticket services without replacing authorization or storage. */
final class SecuritySurfaceOAuthKernel extends ProductionOAuthKernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('oauth.acceptance.websocket_issuer', WebSocketTicketIssuerInterface::class)->setPublic(true);
        $container->setAlias('oauth.acceptance.websocket_redeemer', WebSocketTicketRedeemerInterface::class)->setPublic(true);
        $container->setAlias('oauth.acceptance.websocket_store', WebSocketTicketStoreInterface::class)->setPublic(true);
        $container->setAlias('oauth.acceptance.websocket_status', WebSocketTokenStatusInterface::class)->setPublic(true);
        $container->setAlias('oauth.acceptance.auth_logger', 'monolog.logger.auth')->setPublic(true);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->setParameter('app.websocket.allowed_origins', ['https://baander.app']);
            }
        });
    }
}
