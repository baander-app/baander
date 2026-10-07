<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Mail;

use App\Auth\Domain\Model\User;
use App\Auth\Infrastructure\Mail\AfterResponseMailer;
use App\Auth\Infrastructure\Mail\AuthEmailLocale;
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
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Translation\Formatter\MessageFormatter;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/** Renders auth emails with the real templates and translations and records what would be sent. */
abstract class AuthMailTestCase extends TestCase
{
    protected const string TOKEN = 'a3f1c2d4e5b6978877665544332211000112233445566778899aabbccddeeff0';

    protected Translator $translator;
    protected RequestStack $requestStack;
    protected TestHandler $logs;
    /** @var list<Email> */
    protected array $sent = [];
    protected ?\Throwable $failure = null;

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

    protected function mailer(): AfterResponseMailer
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 5) . '/templates'), ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension($this->translator));

        $transport = new class ($this) implements TransportInterface {
            public function __construct(private readonly AuthMailTestCase $test)
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

        return new AfterResponseMailer($transport, $twig, $this->translator, $this->requestStack, new Logger('test', [$this->logs]), 'Baander');
    }

    protected function locale(): AuthEmailLocale
    {
        return new AuthEmailLocale($this->translator);
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

    protected function terminate(Request $request): TerminateEvent
    {
        return new TerminateEvent($this->createStub(HttpKernelInterface::class), $request, new Response());
    }

    protected function user(): User
    {
        return User::register(new EmailAddress('alice@baander.app'), 'hashed', 'Alice');
    }

    protected function assertLogsHoldNeither(string ...$secrets): void
    {
        foreach ($this->logs->getRecords() as $record) {
            $text = $record->message . json_encode($record->context, JSON_THROW_ON_ERROR);
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $text);
            }
        }
    }
}
