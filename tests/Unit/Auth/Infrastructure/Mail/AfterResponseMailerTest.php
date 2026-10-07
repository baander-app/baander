<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Mail;

use App\Auth\Infrastructure\Mail\CredentialEmail;
use App\Auth\Infrastructure\Mail\MailerPasswordResetDelivery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportException;

final class AfterResponseMailerTest extends AuthMailTestCase
{
    public function testWaitsForTheResponseBeforeSendingWithinARequest(): void
    {
        $mailer = $this->mailer();
        $delivery = new MailerPasswordResetDelivery($mailer, $this->locale(), 'https://baander.app');
        $request = Request::create('/api/auth/password/reset-request', 'POST');
        $this->requestStack->push($request);

        $delivery->deliver($this->user(), self::TOKEN, new \DateTimeImmutable('+60 minutes'));

        self::assertSame([], $this->sent, 'Sending during the request would reveal that the account exists.');

        $mailer->onKernelTerminate($this->terminate(Request::create('/api/other')));
        self::assertSame([], $this->sent, 'Another request finishing does not send this request\'s email.');

        $mailer->onKernelTerminate($this->terminate($request));
        self::assertCount(1, $this->sent);

        $mailer->onKernelTerminate($this->terminate($request));
        self::assertCount(1, $this->sent, 'An email is sent once.');
    }

    public function testSendsAtOnceOutsideARequest(): void
    {
        $this->mailer()->send($this->email());

        self::assertCount(1, $this->sent);
        self::assertSame('alice@baander.app', $this->sent[0]->getTo()[0]->getAddress());
        self::assertSame('Alice', $this->sent[0]->getTo()[0]->getName());
    }

    public function testAFailedSendIsLoggedWithoutTheLinkOrTheAddress(): void
    {
        $this->failure = new TransportException('550 <alice@baander.app> rejected for ' . self::TOKEN);

        $this->mailer()->send($this->email());

        self::assertTrue($this->logs->hasErrorThatContains('Test email could not be sent.'));
        self::assertTrue($this->logs->hasErrorThatPasses(static fn ($record): bool => ($record->context['channel'] ?? null) === 'auth.test'));
        $this->assertLogsHoldNeither(self::TOKEN, 'alice@baander.app');
    }

    public function testAPendingEmailCannotBeSerialized(): void
    {
        $this->expectException(\LogicException::class);

        serialize($this->email());
    }

    private function email(): CredentialEmail
    {
        return new CredentialEmail(
            userId: 'id',
            address: 'alice@baander.app',
            name: 'Alice',
            locale: 'en',
            template: 'email/auth/password_reset',
            subjectKey: 'password_reset_email.subject',
            context: ['link' => 'https://baander.app/reset-password#token=' . self::TOKEN, 'validMinutes' => 60],
            failureMessage: 'Test email could not be sent.',
            logChannel: 'auth.test',
        );
    }
}
