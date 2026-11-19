<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Mail;

use App\Auth\Application\Port\RegistrationAttemptNoticeInterface;
use App\Auth\Domain\Model\User;

/**
 * Emails the owner of a taken address that someone tried to register with it, after the
 * response has been sent (AfterResponseMailer), like the verification email a new account
 * gets. It links to APP_URL/forgot-password for an owner who signed up again because they
 * forgot their password.
 */
final readonly class MailerRegistrationAttemptNotice implements RegistrationAttemptNoticeInterface
{
    public function __construct(
        private AfterResponseMailer $mailer,
        private string $appUrl,
    ) {
    }

    public function deliver(User $owner): void
    {
        $this->mailer->send(new CredentialEmail(
            userId: $owner->getId()->toString(),
            address: $owner->getEmail(),
            name: $owner->getName(),
            template: 'email/auth/registration_attempt',
            subjectKey: 'registration_attempt_email.subject',
            context: [
                'link' => rtrim($this->appUrl, '/') . '/forgot-password',
            ],
            failureMessage: 'Registration attempt notice could not be sent.',
            logChannel: 'auth.registration',
        ));
    }
}
