<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Production OAuth kernel with a WebAuthn relying party for passkey login behind the firewall. */
final class GrantPathOAuthKernel extends ProductionOAuthKernel
{
    public const string RELYING_PARTY = 'baander.app';

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('oauth.acceptance.passkeys', PasskeyRepositoryInterface::class)->setPublic(true);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->setParameter('app.domain', GrantPathOAuthKernel::RELYING_PARTY);
            }
        });
    }
}
