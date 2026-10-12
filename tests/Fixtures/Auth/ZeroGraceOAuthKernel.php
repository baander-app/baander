<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Grant-path OAuth kernel with the lost-response grace disabled (auth.refresh.reuse_grace_seconds: 0). */
final class ZeroGraceOAuthKernel extends GrantPathOAuthKernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->setParameter('auth.refresh.reuse_grace_seconds', 0);
            }
        });
    }
}
