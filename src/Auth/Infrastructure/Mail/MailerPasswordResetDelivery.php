<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Mail;

use App\Auth\Application\Port\PasswordResetDeliveryInterface;
use App\Auth\Domain\Model\User;

/**
 * Emails the password reset link, APP_URL/reset-password#token=…, after the response has
 * been sent (AfterResponseMailer). The fragment keeps the token out of access logs and
 * Referer headers.
 */
final readonly class MailerPasswordResetDelivery implements PasswordResetDeliveryInterface
{
    public function __construct(
        private AfterResponseMailer $mailer,
        private AuthEmailLocale $locale,
        private string $appUrl,
    ) {
    }

    public function deliver(User $user, string $token, \DateTimeImmutable $expiresAt): void
    {
        $this->mailer->send(new CredentialEmail(
            userId: $user->getId()->toString(),
            address: $user->getEmail(),
            name: $user->getName(),
            locale: $this->locale->forUser($user),
            template: 'email/auth/password_reset',
            subjectKey: 'password_reset_email.subject',
            context: [
                'link' => sprintf('%s/reset-password#token=%s', rtrim($this->appUrl, '/'), rawurlencode($token)),
                'validMinutes' => max(1, (int) ceil(($expiresAt->getTimestamp() - time()) / 60)),
            ],
            failureMessage: 'Password reset email could not be sent.',
            logChannel: 'auth.password_reset',
        ));
    }
}
