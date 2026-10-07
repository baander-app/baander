<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Mail;

use App\Auth\Infrastructure\Mail\MailerEmailVerificationDelivery;
use App\Auth\Infrastructure\Mail\MailerPasswordResetDelivery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportException;

/** The password reset and verification emails: link, lifetime, language and failure logging. */
final class CredentialEmailDeliveriesTest extends AuthMailTestCase
{
    public function testThePasswordResetEmailLinksToTheResetPage(): void
    {
        (new MailerPasswordResetDelivery($this->mailer(), 'https://baander.app/'))
            ->deliver($this->user(), self::TOKEN, new \DateTimeImmutable('+30 minutes'));

        self::assertCount(1, $this->sent);
        $email = $this->sent[0];
        self::assertSame('Reset your Baander password', $email->getSubject());
        $link = 'https://baander.app/reset-password#token=' . self::TOKEN;
        self::assertStringContainsString($link, (string) $email->getTextBody());
        self::assertStringContainsString('href="' . $link . '"', (string) $email->getHtmlBody());
        self::assertStringContainsString('expires in 30 minutes', (string) $email->getTextBody());
        self::assertStringContainsString('Hi Alice,', (string) $email->getTextBody());
    }

    public function testTheVerificationEmailLinksToTheVerifyPage(): void
    {
        (new MailerEmailVerificationDelivery($this->mailer(), 'https://baander.app/'))
            ->deliver($this->user(), self::TOKEN, new \DateTimeImmutable('+24 hours'));

        self::assertCount(1, $this->sent);
        $email = $this->sent[0];
        self::assertSame('alice@baander.app', $email->getTo()[0]->getAddress());
        self::assertSame('Verify your email address for Baander', $email->getSubject());
        $link = 'https://baander.app/verify-email#token=' . self::TOKEN;
        self::assertStringContainsString($link, (string) $email->getTextBody());
        self::assertStringContainsString('href="' . $link . '"', (string) $email->getHtmlBody());
        self::assertStringContainsString('expires in 24 hours', (string) $email->getTextBody());
        self::assertStringContainsString('Hi Alice,', (string) $email->getTextBody());
    }

    public function testBothEmailsUseTheRecipientsLanguageResolvedWhenTheyAreSent(): void
    {
        $mailer = $this->mailer();
        $user = $this->user();
        $request = Request::create('/api/auth/register', 'POST');
        $this->requestStack->push($request);

        (new MailerPasswordResetDelivery($mailer, 'https://baander.app'))
            ->deliver($user, self::TOKEN, new \DateTimeImmutable('+1 minute'));
        (new MailerEmailVerificationDelivery($mailer, 'https://baander.app'))
            ->deliver($user, self::TOKEN, new \DateTimeImmutable('+1 hour'));
        // The language changes after the emails were queued; the one in force when they are sent wins.
        $this->languages[$user->getId()->toString()] = 'da';
        $mailer->onKernelTerminate($this->terminate($request));

        self::assertCount(2, $this->sent);
        self::assertSame('Nulstil din adgangskode til Baander', $this->sent[0]->getSubject());
        self::assertStringContainsString('udløber om 1 minut.', (string) $this->sent[0]->getTextBody());
        self::assertSame('Bekræft din e-mailadresse til Baander', $this->sent[1]->getSubject());
        self::assertStringContainsString('udløber om 1 time.', (string) $this->sent[1]->getTextBody());
        self::assertStringContainsString('<html lang="da">', (string) $this->sent[1]->getHtmlBody());
        self::assertSame('en', $this->translator->getLocale(), 'The shared translator keeps its locale.');
    }

    public function testThaiVerificationEmailRenders(): void
    {
        $user = $this->user();
        $this->languages[$user->getId()->toString()] = 'th';

        (new MailerEmailVerificationDelivery($this->mailer(), 'https://baander.app'))
            ->deliver($user, self::TOKEN, new \DateTimeImmutable('+24 hours'));

        self::assertSame('ยืนยันที่อยู่อีเมลของคุณสำหรับ Baander', $this->sent[0]->getSubject());
        self::assertStringContainsString('24 ชั่วโมง', (string) $this->sent[0]->getTextBody());
        self::assertStringContainsString('<html lang="th">', (string) $this->sent[0]->getHtmlBody());
    }

    public function testAFailedVerificationEmailIsLoggedWithoutTheTokenOrTheAddress(): void
    {
        $this->failure = new TransportException('550 <alice@baander.app> rejected for ' . self::TOKEN);

        (new MailerEmailVerificationDelivery($this->mailer(), 'https://baander.app'))
            ->deliver($this->user(), self::TOKEN, new \DateTimeImmutable('+24 hours'));

        self::assertTrue($this->logs->hasErrorThatContains('Verification email could not be sent.'));
        $this->assertLogsHoldNeither(self::TOKEN, 'alice@baander.app');
    }
}
