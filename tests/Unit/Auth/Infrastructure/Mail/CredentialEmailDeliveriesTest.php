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
        (new MailerPasswordResetDelivery($this->mailer(), $this->locale(), 'https://baander.app/'))
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
        (new MailerEmailVerificationDelivery($this->mailer(), $this->locale(), 'https://baander.app/'))
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

    public function testBothEmailsUseTheLocaleChosenWhenTheyWereQueued(): void
    {
        $mailer = $this->mailer();
        $request = Request::create('/api/auth/register', 'POST');
        $this->requestStack->push($request);
        $this->translator->setLocale('da');

        (new MailerPasswordResetDelivery($mailer, $this->locale(), 'https://baander.app'))
            ->deliver($this->user(), self::TOKEN, new \DateTimeImmutable('+1 minute'));
        (new MailerEmailVerificationDelivery($mailer, $this->locale(), 'https://baander.app'))
            ->deliver($this->user(), self::TOKEN, new \DateTimeImmutable('+1 hour'));
        // LocaleListener resets the translator before kernel.terminate.
        $this->translator->setLocale('en');
        $mailer->onKernelTerminate($this->terminate($request));

        self::assertCount(2, $this->sent);
        self::assertSame('Nulstil din adgangskode til Baander', $this->sent[0]->getSubject());
        self::assertStringContainsString('udløber om 1 minut.', (string) $this->sent[0]->getTextBody());
        self::assertSame('Bekræft din e-mailadresse til Baander', $this->sent[1]->getSubject());
        self::assertStringContainsString('udløber om 1 time.', (string) $this->sent[1]->getTextBody());
        self::assertStringContainsString('<html lang="da">', (string) $this->sent[1]->getHtmlBody());
    }

    public function testThaiVerificationEmailRenders(): void
    {
        $this->translator->setLocale('th');

        (new MailerEmailVerificationDelivery($this->mailer(), $this->locale(), 'https://baander.app'))
            ->deliver($this->user(), self::TOKEN, new \DateTimeImmutable('+24 hours'));

        self::assertSame('ยืนยันที่อยู่อีเมลของคุณสำหรับ Baander', $this->sent[0]->getSubject());
        self::assertStringContainsString('24 ชั่วโมง', (string) $this->sent[0]->getTextBody());
    }

    public function testAFailedVerificationEmailIsLoggedWithoutTheTokenOrTheAddress(): void
    {
        $this->failure = new TransportException('550 <alice@baander.app> rejected for ' . self::TOKEN);

        (new MailerEmailVerificationDelivery($this->mailer(), $this->locale(), 'https://baander.app'))
            ->deliver($this->user(), self::TOKEN, new \DateTimeImmutable('+24 hours'));

        self::assertTrue($this->logs->hasErrorThatContains('Verification email could not be sent.'));
        $this->assertLogsHoldNeither(self::TOKEN, 'alice@baander.app');
    }
}
