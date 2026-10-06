<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use League\OAuth2\Server\AuthorizationServer;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Production OAuth kernel that also exposes League's server for redemption checks behind the firewall. */
final class GrantPathOAuthKernel extends ProductionOAuthKernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('oauth.acceptance.authorization_server', AuthorizationServer::class)->setPublic(true);
    }
}
