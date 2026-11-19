<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Mail;

use App\Auth\Application\Port\EmailChangeNoticeInterface;
use App\Auth\Domain\Model\User;
use App\Shared\Domain\Model\Email;

/**
 * Emails an account's previous address that the account's email changed, after the response
 * has been sent (AfterResponseMailer). It does not name the new address.
 */
final readonly class MailerEmailChangeNotice implements EmailChangeNoticeInterface
{
    public function __construct(
        private AfterResponseMailer $mailer,
    ) {
    }

    public function deliver(User $user, Email $previousAddress): void
    {
        $this->mailer->send(new CredentialEmail(
            userId: $user->getId()->toString(),
            address: $previousAddress->toString(),
            name: $user->getName(),
            template: 'email/auth/email_changed',
            subjectKey: 'email_changed_email.subject',
            context: [],
            failureMessage: 'Email change notice could not be sent.',
            logChannel: 'auth.email_change',
        ));
    }
}
