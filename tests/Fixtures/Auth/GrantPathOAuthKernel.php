<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Shared\Application\Http\PublicOrigins;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Production OAuth kernel for passkey login behind the firewall. Its public URL is the issuer's,
 * https://baander.app, so that host is the WebAuthn relying party.
 */
class GrantPathOAuthKernel extends ProductionOAuthKernel
{
    public const string RELYING_PARTY = 'baander.app';

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('oauth.acceptance.passkeys', PasskeyRepositoryInterface::class)->setPublic(true);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition(PublicOrigins::class)
                    ->setArgument('$publicUrl', 'https://' . GrantPathOAuthKernel::RELYING_PARTY);
            }
        });
    }
}
