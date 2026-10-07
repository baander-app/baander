<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Mail;

use App\Auth\Domain\Model\User;
use App\Auth\Infrastructure\Mail\AfterResponseMailer;
use App\Shared\Domain\Model\Email as EmailAddress;
use App\UserPreference\Application\Port\UserSettingsContractInterface;
use App\UserPreference\Application\Port\UserSettingView;
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
    /** @var array<string, string> email language per user id */
    protected array $languages = [];
    /** @var list<string> user ids whose language was looked up, in order */
    protected array $languageLookups = [];
    protected ?\Throwable $languageFailure = null;

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

        $settings = new class ($this) implements UserSettingsContractInterface {
            public function __construct(private readonly AuthMailTestCase $test)
            {
            }

            public function resolveLanguage(string $userId): string
            {
                return $this->test->resolveLanguage($userId);
            }

            public function seedLanguage(string $userId, string $language): void
            {
                throw new \LogicException('Not used by the mailer.');
            }

            public function settings(string $userId): array
            {
                throw new \LogicException('Not used by the mailer.');
            }

            public function setting(string $userId, string $key): UserSettingView
            {
                throw new \LogicException('Not used by the mailer.');
            }

            public function set(string $userId, string $key, mixed $value): void
            {
                throw new \LogicException('Not used by the mailer.');
            }

            public function reset(string $userId, string $key): void
            {
                throw new \LogicException('Not used by the mailer.');
            }
        };

        return new AfterResponseMailer($transport, $twig, $this->translator, $settings, $this->requestStack, new Logger('test', [$this->logs]), 'Baander');
    }

    /**
     * @internal called by the recording settings contract
     */
    public function resolveLanguage(string $userId): string
    {
        $this->languageLookups[] = $userId;
        if ($this->languageFailure !== null) {
            throw $this->languageFailure;
        }

        return $this->languages[$userId] ?? 'en';
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
