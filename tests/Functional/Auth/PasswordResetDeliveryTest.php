<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Infrastructure\Mail\AfterResponseMailer;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Domain\Model\Email as EmailAddress;
use Doctrine\DBAL\Connection;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Covers the reset email from request to redemption. The test mailer DSN is null://, so
 * nothing leaves the process; Symfony's message logger records each email.
 */
final class PasswordResetDeliveryTest extends RateLimitTestCase
{
    private const string LINK_PATTERN = '~(https?://[^\s"<]+/reset-password#token=([0-9a-f]{64}))~';

    public function testAnExistingAccountGetsOneEmailWhoseLinkResetsThePassword(): void
    {
        $user = $this->createTestUser(name: 'Alice Example');
        $logs = $this->captureLogs();

        $response = $this->requestReset($user->getEmail());

        $this->assertSame($this->expectedRequestBody(), $response->getContent());
        $this->assertEmailCount(1);
        $email = $this->onlyEmail();
        $this->assertSame([$user->getEmail()], array_map(static fn (Address $a): string => $a->getAddress(), $email->getTo()));
        $this->assertNotEmpty($email->getFrom(), 'MAIL_FROM_ADDRESS supplies the sender.');
        $this->assertStringContainsString('Reset your', (string) $email->getSubject());

        [$link, $token] = $this->linkFrom((string) $email->getTextBody());
        $appUrl = rtrim((string) ($_SERVER['APP_URL'] ?? $_ENV['APP_URL'] ?? ''), '/');
        $this->assertSame($appUrl . '/reset-password#token=' . $token, $link, 'The link points at the web reset page and keeps the token in the fragment.');
        $this->assertStringContainsString($link, (string) $email->getHtmlBody());
        $minutes = static::getContainer()->getParameter('auth.password_reset_token.lifetime_minutes');
        $this->assertIsInt($minutes);
        $this->assertStringContainsString(sprintf('expires in %d minutes', $minutes), (string) $email->getTextBody());

        $this->assertTokenNotStored($token, $logs);

        $redeemed = $this->anonymousRequest('POST', '/api/auth/password/reset', ['token' => $token, 'password' => 'emailed-new-password']);
        $this->assertJsonResponse($redeemed, 200, 'data');
        $this->assertPassword($user->getEmail(), 'emailed-new-password');
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM password_reset_tokens WHERE user_id = ?', [$user->getId()->toString()]));
    }

    public function testTheEmailIsInTheServerDefaultLanguageForAUserWithoutAChoice(): void
    {
        $user = $this->createTestUser(name: 'Alice Example');
        static::getContainer()->get(SystemSettingStoreInterface::class)->save(['i18n.default_language' => 'da']);

        $this->requestReset($user->getEmail());

        $email = $this->onlyEmail();
        $this->assertSame('Nulstil din adgangskode til ' . static::getContainer()->getParameter('app.name'), $email->getSubject());
        $this->assertStringContainsString('Hej Alice Example', (string) $email->getTextBody());
        $this->assertStringContainsString('<html lang="da">', (string) $email->getHtmlBody());
    }

    public function testAnUnknownAddressGetsTheSameResponseAndNoEmail(): void
    {
        $response = $this->requestReset('nobody-' . bin2hex(random_bytes(4)) . '@baander.app');

        $this->assertSame($this->expectedRequestBody(), $response->getContent());
        $this->assertEmailCount(0);
    }

    public function testNoEmailIsSentOnceTheAccountLimitIsReached(): void
    {
        $user = $this->createTestUser();
        $this->exhaust('auth_password_reset_email', $user->getEmail());

        $response = $this->requestReset($user->getEmail());

        $this->assertSame($this->expectedRequestBody(), $response->getContent());
        $this->assertEmailCount(0);
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM password_reset_tokens WHERE user_id = ?', [$user->getId()->toString()]));
    }

    public function testADeliveryFailureStillAnswersTheSameAndLogsNeitherTokenNorAddress(): void
    {
        $user = $this->createTestUser();
        $transport = new class implements TransportInterface {
            public ?string $attemptedText = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                if ($message instanceof Email) {
                    $this->attemptedText = (string) $message->getTextBody();
                }

                throw new TransportException('550 mailbox unavailable for ' . implode(', ', array_map(static fn (Address $a): string => $a->getAddress(), $message instanceof Email ? $message->getTo() : [])));
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        };
        $logs = new TestHandler();
        $container = static::getContainer();
        $translator = $container->get(TranslatorInterface::class);
        $this->assertInstanceOf(TranslatorInterface::class, $translator);
        $twig = $container->get(Environment::class);
        $this->assertInstanceOf(Environment::class, $twig);
        $requestStack = $container->get(RequestStack::class);
        $this->assertInstanceOf(RequestStack::class, $requestStack);
        $container->set(AfterResponseMailer::class, new AfterResponseMailer(
            $transport,
            $twig,
            $translator,
            $container->get('test.user_settings_contract'),
            $requestStack,
            new Logger('test', [$logs]),
            'Baander',
        ));

        $response = $this->requestReset($user->getEmail());

        $this->assertSame($this->expectedRequestBody(), $response->getContent());
        $this->assertNotNull($transport->attemptedText, 'Delivery was attempted after the response.');
        [, $token] = $this->linkFrom($transport->attemptedText);
        $this->assertTrue($logs->hasErrorThatContains('Password reset email could not be sent.'));
        foreach ($logs->getRecords() as $record) {
            $text = $this->recordText($record);
            $this->assertStringNotContainsString($token, $text);
            $this->assertStringNotContainsString($user->getEmail(), $text);
        }
    }

    private function requestReset(string $email): Response
    {
        $response = $this->requestFrom('198.51.100.' . random_int(60, 250), 'POST', '/api/auth/password/reset-request', content: ['email' => $email]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $response;
    }

    private function expectedRequestBody(): string
    {
        return json_encode(['data' => ['message' => 'If the email exists, a password reset link has been sent.']], JSON_THROW_ON_ERROR);
    }

    private function onlyEmail(): Email
    {
        $messages = $this->getMailerMessages();
        $this->assertCount(1, $messages);
        $this->assertInstanceOf(Email::class, $messages[0]);

        return $messages[0];
    }

    /** @return array{string, string} */
    private function linkFrom(string $body): array
    {
        $this->assertSame(1, preg_match(self::LINK_PATTERN, $body, $matches), $body);

        return [$matches[1], $matches[2]];
    }

    private function captureLogs(): TestHandler
    {
        $logger = static::getContainer()->get('logger');
        $this->assertInstanceOf(Logger::class, $logger);
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        return $handler;
    }

    private function assertTokenNotStored(string $token, TestHandler $logs): void
    {
        foreach (['domain_event_outbox', 'domain_event_outbox_delivery', 'failed_messages', 'password_reset_tokens'] as $table) {
            $this->assertSame(0, (int) $this->connection()->fetchOne(
                sprintf('SELECT count(*) FROM %s AS t WHERE strpos(t::text, ?) > 0', $table),
                [$token],
            ), sprintf('The raw token must not be stored in %s.', $table));
        }

        foreach (['async', 'swoole_task', 'scheduler'] as $name) {
            $transport = static::getContainer()->get('messenger.transport.' . $name);
            $this->assertInstanceOf(InMemoryTransport::class, $transport);
            foreach ([...$transport->getSent(), ...$transport->get()] as $envelope) {
                $this->assertStringNotContainsString($token, print_r($envelope, true), sprintf('The raw token must not enter the %s transport.', $name));
            }
        }

        foreach ($logs->getRecords() as $record) {
            $this->assertStringNotContainsString($token, $this->recordText($record));
        }
    }

    private function recordText(LogRecord $record): string
    {
        return $record->message . ' ' . json_encode($record->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private function assertPassword(string $email, string $expected): void
    {
        $this->entityManager->clear();
        $user = $this->userRepository->findByEmail(new EmailAddress($email));
        $this->assertNotNull($user);
        $hasher = static::getContainer()->get(PasswordHasherInterface::class);
        $this->assertInstanceOf(PasswordHasherInterface::class, $hasher);
        $this->assertTrue($hasher->verify($expected, $user->getPassword()));
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
