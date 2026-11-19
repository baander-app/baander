<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\MessageBusInterface;

/** Production OAuth kernel that also exposes the password reset token store and the command bus to tests. */
final class PasswordChangeOAuthKernel extends ProductionOAuthKernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('oauth.acceptance.password_reset_tokens', PasswordResetTokenRepositoryInterface::class)->setPublic(true);
        $container->setAlias('oauth.acceptance.bus', MessageBusInterface::class)->setPublic(true);
    }
}
