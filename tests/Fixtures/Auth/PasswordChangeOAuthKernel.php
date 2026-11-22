<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;
use App\Auth\Application\Port\TotpSecretCipherInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Production OAuth kernel with a WebAuthn relying party, for the credential change tests: it also
 * exposes the password reset and email verification token stores, the TOTP secret cipher and the
 * command bus.
 */
final class PasswordChangeOAuthKernel extends GrantPathOAuthKernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('oauth.acceptance.password_reset_tokens', PasswordResetTokenRepositoryInterface::class)->setPublic(true);
        $container->setAlias('oauth.acceptance.totp_cipher', TotpSecretCipherInterface::class)->setPublic(true);
        $container->setAlias('oauth.acceptance.email_verification_tokens', EmailVerificationTokenRepositoryInterface::class)->setPublic(true);
        $container->setAlias('oauth.acceptance.bus', MessageBusInterface::class)->setPublic(true);
    }
}
