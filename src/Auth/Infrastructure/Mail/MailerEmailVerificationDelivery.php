<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Mail;

use App\Auth\Application\Port\EmailVerificationDeliveryInterface;
use App\Auth\Domain\Model\User;

/**
 * Emails the verification link, APP_URL/verify-email#token=…, to the user's current address
 * after the response has been sent (AfterResponseMailer). The fragment keeps the token out of
 * access logs and Referer headers.
 */
final readonly class MailerEmailVerificationDelivery implements EmailVerificationDeliveryInterface
{
    public function __construct(
        private AfterResponseMailer $mailer,
        private string $appUrl,
    ) {
    }

    public function deliver(User $user, string $token, \DateTimeImmutable $expiresAt): void
    {
        $this->mailer->send(new CredentialEmail(
            userId: $user->getId()->toString(),
            address: $user->getEmail(),
            name: $user->getName(),
            template: 'email/auth/email_verification',
            subjectKey: 'email_verification_email.subject',
            context: [
                'link' => sprintf('%s/verify-email#token=%s', rtrim($this->appUrl, '/'), rawurlencode($token)),
                'validHours' => max(1, (int) ceil(($expiresAt->getTimestamp() - time()) / 3600)),
            ],
            failureMessage: 'Verification email could not be sent.',
            logChannel: 'auth.email_verification',
        ));
    }
}
