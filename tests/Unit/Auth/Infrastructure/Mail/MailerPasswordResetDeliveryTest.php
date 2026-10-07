<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Mail;

use App\Auth\Domain\Model\User;
use App\Auth\Infrastructure\Mail\MailerPasswordResetDelivery;
use App\Auth\Infrastructure\Mail\PasswordResetEmail;
use App\Shared\Domain\Model\Email as EmailAddress;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Translation\Formatter\MessageFormatter;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class MailerPasswordResetDeliveryTest extends TestCase
{
    private const string TOKEN = 'a3f1c2d4e5b6978877665544332211000112233445566778899aabbccddeeff0';

    private Translator $translator;
    private RequestStack $requestStack;
    private TestHandler $logs;
    /** @var list<Email> */
    private array $sent = [];
    private ?\Throwable $failure = null;

    protected function setUp(): void
    {
        $this->translator = new Translator('en', new MessageFormatter());
        $this->translator->addLoader('yaml', new YamlFileLoader());
        foreach (['en', 'da', 'th'] as $locale) {
            $this->translator->addResource('yaml', sprintf('%s/translations/auth+intl-icu.%s.yaml', dirname(__DIR__, 5), $locale), $locale, 'auth+intl-icu');
        }
        $this->requestStack = new RequestStack();
        $this->logs = new TestHandler();
    }

    public function testWaitsForTheResponseBeforeSendingWithinARequest(): void
    {
        $delivery = $this->delivery();
        $request = Request::create('/api/auth/password/reset-request', 'POST');
        $this->requestStack->push($request);

        $delivery->deliver($this->user(), self::TOKEN, new \DateTimeImmutable('+60 minutes'));

        self::assertSame([], $this->sent, 'Sending during the request would reveal that the account exists.');

        $delivery->onKernelTerminate($this->terminate(Request::create('/api/other')));
        self::assertSame([], $this->sent, 'Another request finishing does not send this request\'s email.');

        $delivery->onKernelTerminate($this->terminate($request));
        self::assertCount(1, $this->sent);

        $delivery->onKernelTerminate($this->terminate($request));
        self::assertCount(1, $this->sent, 'An email is sent once.');
    }

    public function testSendsAtOnceOutsideARequest(): void
    {
        $this->delivery()->deliver($this->user(), self::TOKEN, new \DateTimeImmutable('+30 minutes'));

        self::assertCount(1, $this->sent);
        $email = $this->sent[0];
        self::assertSame('alice@baander.app', $email->getTo()[0]->getAddress());
        self::assertSame('Alice', $email->getTo()[0]->getName());
        self::assertSame('Reset your Baander password', $email->getSubject());
        $link = 'https://baander.app/reset-password#token=' . self::TOKEN;
        self::assertStringContainsString($link, (string) $email->getTextBody());
        self::assertStringContainsString('href="' . $link . '"', (string) $email->getHtmlBody());
        self::assertStringContainsString('expires in 30 minutes', (string) $email->getTextBody());
        self::assertStringContainsString('Hi Alice,', (string) $email->getTextBody());
    }

    public function testUsesTheLocaleOfTheRequestThatAskedForTheReset(): void
    {
        $delivery = $this->delivery();
        $request = Request::create('/api/auth/password/reset-request', 'POST');
        $this->requestStack->push($request);
        $this->translator->setLocale('da');

        $delivery->deliver($this->user(), self::TOKEN, new \DateTimeImmutable('+1 minute'));
        // LocaleListener resets the translator before kernel.terminate.
        $this->translator->setLocale('en');
        $delivery->onKernelTerminate($this->terminate($request));

        self::assertCount(1, $this->sent);
        self::assertSame('Nulstil din adgangskode til Baander', $this->sent[0]->getSubject());
        self::assertStringContainsString('udløber om 1 minut.', (string) $this->sent[0]->getTextBody());
        self::assertStringContainsString('<html lang="da">', (string) $this->sent[0]->getHtmlBody());
    }

    public function testAFailedSendIsLoggedWithoutTheTokenOrTheAddress(): void
    {
        $this->failure = new TransportException('550 <alice@baander.app> rejected for ' . self::TOKEN);

        $this->delivery()->deliver($this->user(), self::TOKEN, new \DateTimeImmutable('+60 minutes'));

        self::assertTrue($this->logs->hasErrorThatContains('Password reset email could not be sent.'));
        foreach ($this->logs->getRecords() as $record) {
            $text = $record->message . json_encode($record->context, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString(self::TOKEN, $text);
            self::assertStringNotContainsString('alice@baander.app', $text);
        }
    }

    public function testAPendingEmailCannotBeSerialized(): void
    {
        $this->expectException(\LogicException::class);

        serialize(new PasswordResetEmail('id', 'alice@baander.app', 'Alice', 'https://baander.app/reset-password#token=x', 60, 'en'));
    }

    private function delivery(): MailerPasswordResetDelivery
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 5) . '/templates'), ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension($this->translator));

        $transport = new class ($this) implements TransportInterface {
            public function __construct(private readonly MailerPasswordResetDeliveryTest $test)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->test->record($message);

                return null;
            }

            public function __toString(): string
            {
                return 'recording://';
            }
        };

        return new MailerPasswordResetDelivery(
            $transport,
            $twig,
            $this->translator,
            $this->requestStack,
            new Logger('test', [$this->logs]),
            'https://baander.app/',
            'Baander',
        );
    }

    /**
     * @internal called by the recording transport
     */
    public function record(RawMessage $message): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        self::assertInstanceOf(Email::class, $message);
        $this->sent[] = $message;
    }

    private function terminate(Request $request): TerminateEvent
    {
        return new TerminateEvent($this->createStub(HttpKernelInterface::class), $request, new Response());
    }

    private function user(): User
    {
        return User::register(new EmailAddress('alice@baander.app'), 'hashed', 'Alice');
    }
}
