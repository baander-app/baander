<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Mail;

/**
 * An email that carries a credential in a link, such as a password reset or an email
 * verification, waiting to be sent by AfterResponseMailer. Held in memory only, never
 * serialized.
 */
final readonly class CredentialEmail
{
    /**
     * @param string               $userId         the recipient, whose email language is read when the email is sent
     * @param string               $template       template path without the `.txt.twig` or `.html.twig` suffix
     * @param string               $subjectKey     key in the `auth` translation domain; receives `app`
     * @param array<string, mixed> $context        template variables besides appName, name and locale
     * @param string               $failureMessage logged, without the address or the link, when sending fails
     */
    public function __construct(
        public string $userId,
        public string $address,
        public string $name,
        public string $template,
        public string $subjectKey,
        public array $context,
        public string $failureMessage,
        public string $logChannel,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('An email that carries a credential must not be serialized.');
    }
}
