<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Domain\Model\User;
use App\Auth\Infrastructure\Mail\AfterResponseMailer;
use Doctrine\DBAL\Connection;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
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
 * Covers the verification email from registration or an email change to redemption. The
 * test mailer DSN is null://, so nothing leaves the process; Symfony's message logger
 * records each email.
 */
final class EmailVerificationDeliveryTest extends RateLimitTestCase
{
    private const string LINK_PATTERN = '~(https?://[^\s"<]+/verify-email#token=([0-9a-f]{64}))~';

    public function testRegistrationSendsOneEmailWhoseLinkVerifiesTheAddress(): void
    {
        $logs = $this->captureLogs();
        $address = $this->uniqueEmail();

        $registered = $this->requestFrom($this->ip(), 'POST', '/api/auth/register', content: [
            'name' => 'Vera Example',
            'email' => $address,
            'password' => 'registration-password-1',
        ]);
        $body = $this->assertJsonResponse($registered, 201, 'data');
        $this->assertNull($body['data']['emailVerifiedAt']);

        $this->assertEmailCount(1);
        $email = $this->onlyEmail();
        $this->assertSame([$address], array_map(static fn (Address $a): string => $a->getAddress(), $email->getTo()));
        $this->assertNotEmpty($email->getFrom(), 'MAIL_FROM_ADDRESS supplies the sender.');
        $this->assertStringContainsString('Verify your email', (string) $email->getSubject());

        [$link, $token] = $this->linkFrom((string) $email->getTextBody());
        $appUrl = rtrim((string) ($_SERVER['APP_URL'] ?? $_ENV['APP_URL'] ?? ''), '/');
        $this->assertSame($appUrl . '/verify-email#token=' . $token, $link, 'The link points at the web page and keeps the token in the fragment.');
        $this->assertStringContainsString($link, (string) $email->getHtmlBody());
        $this->assertStringContainsString('expires in 24 hours', (string) $email->getTextBody());

        $this->assertTokenNotStored($token, $logs);
        $userId = (string) $this->connection()->fetchOne('SELECT id FROM users WHERE email = ?', [$address]);
        $this->assertSame(
            hash('sha256', $token),
            $this->connection()->fetchOne('SELECT token_hash FROM email_verification_tokens WHERE user_id = ?', [$userId]),
            'Only the SHA-256 hash of the token is stored.',
        );

        $this->assertJsonResponse($this->verify($token), 200, 'data');
        $this->assertNotNull($this->connection()->fetchOne('SELECT email_verified_at FROM users WHERE id = ?', [$userId]));
        $this->assertSame(0, $this->outstandingTokens($userId));

        $replay = $this->verify($token);
        $this->assertJsonResponse($replay, 400, 'error');
    }

    public function testADeliveryFailureDoesNotFailRegistrationAndLogsNeitherTokenNorAddress(): void
    {
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
        $container->set(AfterResponseMailer::class, new AfterResponseMailer($transport, $twig, $translator, $requestStack, new Logger('test', [$logs]), 'Baander'));
        $address = $this->uniqueEmail();

        $response = $this->requestFrom($this->ip(), 'POST', '/api/auth/register', content: [
            'name' => 'Vera Example',
            'email' => $address,
            'password' => 'registration-password-1',
        ]);

        $this->assertJsonResponse($response, 201, 'data');
        $this->assertNotNull($transport->attemptedText, 'Delivery was attempted after the response.');
        [, $token] = $this->linkFrom($transport->attemptedText);
        $this->assertTrue($logs->hasErrorThatContains('Verification email could not be sent.'));
        foreach ($logs->getRecords() as $record) {
            $text = $this->recordText($record);
            $this->assertStringNotContainsString($token, $text);
            $this->assertStringNotContainsString($address, $text);
        }
        $userId = (string) $this->connection()->fetchOne('SELECT id FROM users WHERE email = ?', [$address]);
        $this->assertSame(1, $this->outstandingTokens($userId), 'The account and its token stay; the user can ask for a new link.');
    }

    public function testTheTokenTableHoldsNoPlainTextToken(): void
    {
        $columns = $this->connection()->fetchFirstColumn(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'email_verification_tokens' ORDER BY column_name",
        );

        $this->assertSame(['created_at', 'email', 'expires_at', 'token_hash', 'user_id'], $columns);
    }

    public function testChangingTheEmailSendsANewLinkToTheNewAddressAndEndsTheOldOne(): void
    {
        $user = $this->registerThroughTheApi();
        [, $oldToken] = $this->linkFrom((string) $this->onlyEmail()->getTextBody());
        $newAddress = $this->uniqueEmail();

        $changed = $this->authenticatedRequest('PUT', '/api/auth/me/email', $user, ['email' => $newAddress]);
        $this->assertJsonResponse($changed, 200, 'data');

        $messages = $this->getMailerMessages();
        $this->assertCount(2, $messages);
        $latest = $messages[1];
        $this->assertInstanceOf(Email::class, $latest);
        $this->assertSame([$newAddress], array_map(static fn (Address $a): string => $a->getAddress(), $latest->getTo()));
        [, $newToken] = $this->linkFrom((string) $latest->getTextBody());

        $this->assertJsonResponse($this->verify($oldToken), 400, 'error');
        $this->assertJsonResponse($this->verify($newToken), 200, 'data');
        $this->assertSame($newAddress, $this->connection()->fetchOne('SELECT email FROM users WHERE id = ? AND email_verified_at IS NOT NULL', [$user->getId()->toString()]));
    }

    public function testAnAdministratorEmailChangeSendsALinkToTheNewAddress(): void
    {
        $user = $this->createTestUser($this->uniqueEmail());
        $newAddress = $this->uniqueEmail();

        $response = $this->authenticatedRequest('PATCH', '/api/admin/users/' . $user->getId()->toString(), $this->createSuperAdminUser(), ['email' => $newAddress]);

        $body = $this->assertJsonResponse($response, 200, 'data');
        $this->assertSame($newAddress, $body['data']['email']);
        $this->assertNull($body['data']['emailVerifiedAt']);
        $email = $this->onlyEmail();
        $this->assertSame([$newAddress], array_map(static fn (Address $a): string => $a->getAddress(), $email->getTo()));
        [, $token] = $this->linkFrom((string) $email->getTextBody());
        $this->assertJsonResponse($this->verify($token), 200, 'data');
    }

    public function testTheCliEmailChangeSendsALinkToTheNewAddress(): void
    {
        $user = $this->createSuperAdminUser();
        $newAddress = $this->uniqueEmail();
        $kernel = static::$kernel;
        $this->assertNotNull($kernel);

        $tester = new CommandTester((new Application($kernel))->find('app:user:change-email'));
        $tester->execute(['identifier' => $user->getEmail(), 'email' => $newAddress]);

        $tester->assertCommandIsSuccessful();
        $this->assertSame($newAddress, $this->connection()->fetchOne('SELECT email FROM users WHERE id = ? AND email_verified_at IS NULL', [$user->getId()->toString()]));
        $email = $this->onlyEmail();
        $this->assertSame([$newAddress], array_map(static fn (Address $a): string => $a->getAddress(), $email->getTo()));
        [, $token] = $this->linkFrom((string) $email->getTextBody());
        $this->assertJsonResponse($this->verify($token), 200, 'data');
    }

    public function testAnUnchangedEmailSendsNothing(): void
    {
        $user = $this->createTestUser($this->uniqueEmail());

        $this->assertJsonResponse($this->authenticatedRequest('PUT', '/api/auth/me/email', $user, ['email' => $user->getEmail()]), 200, 'data');

        $this->assertEmailCount(0);
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $user = $this->registerThroughTheApi();
        [, $token] = $this->linkFrom((string) $this->onlyEmail()->getTextBody());
        $this->connection()->executeStatement(
            "UPDATE email_verification_tokens SET created_at = now() - interval '2 days', expires_at = now() - interval '1 second' WHERE user_id = ?",
            [$user->getId()->toString()],
        );

        $this->assertJsonResponse($this->verify($token), 400, 'error');
        $this->assertNull($this->connection()->fetchOne('SELECT email_verified_at FROM users WHERE id = ?', [$user->getId()->toString()]));
    }

    public function testDeletingTheUserRemovesTheirToken(): void
    {
        $user = $this->registerThroughTheApi();
        $this->assertSame(1, $this->outstandingTokens($user->getId()->toString()));

        $this->userRepository->delete($user->getId());

        $this->assertSame(0, $this->outstandingTokens($user->getId()->toString()));
    }

    public function testResendSendsANewLinkAndEndsTheEarlierOne(): void
    {
        $user = $this->registerThroughTheApi();
        [, $first] = $this->linkFrom((string) $this->onlyEmail()->getTextBody());

        $response = $this->resend($user);

        $this->assertSame($this->expectedResendBody(), $response->getContent());
        $messages = $this->getMailerMessages();
        $this->assertCount(2, $messages);
        $this->assertInstanceOf(Email::class, $messages[1]);
        [, $second] = $this->linkFrom((string) $messages[1]->getTextBody());
        $this->assertNotSame($first, $second);
        $this->assertSame(1, $this->outstandingTokens($user->getId()->toString()));

        $this->assertJsonResponse($this->verify($first), 400, 'error');
        $this->assertJsonResponse($this->verify($second), 200, 'data');
    }

    public function testResendAnswersTheSameForAVerifiedAccountAndSendsNothing(): void
    {
        $verified = $this->createSuperAdminUser();

        $response = $this->resend($verified);

        $this->assertSame($this->expectedResendBody(), $response->getContent());
        $this->assertEmailCount(0);
    }

    public function testResendOverTheUserLimitAnswersTheSameAndSendsNothing(): void
    {
        $user = $this->createTestUser($this->uniqueEmail());
        $this->exhaust('auth_email_verification_user', $user->getId()->toString());

        $response = $this->resend($user);

        $this->assertSame($this->expectedResendBody(), $response->getContent());
        $this->assertNull($response->headers->get('Retry-After'));
        $this->assertEmailCount(0);
        $this->assertSame(0, $this->outstandingTokens($user->getId()->toString()));
    }

    public function testResendAndRedemptionAreLimitedPerIp(): void
    {
        $user = $this->createTestUser($this->uniqueEmail());
        $ip = $this->ip();
        $this->exhaust('auth_email_verification_ip', $ip);

        $this->assertTooManyRequests($this->requestFrom($ip, 'POST', '/api/auth/me/email/verification', $user->getId()->toString()));
        $this->assertTooManyRequests($this->requestFrom($ip, 'POST', '/api/auth/email/verify', content: ['token' => str_repeat('0', 64)]));
        $this->assertEmailCount(0);
    }

    public function testResendRequiresSignIn(): void
    {
        $response = $this->requestFrom($this->ip(), 'POST', '/api/auth/me/email/verification');

        $this->assertSame(401, $response->getStatusCode(), (string) $response->getContent());
    }

    private function registerThroughTheApi(): User
    {
        $address = $this->uniqueEmail();
        $response = $this->requestFrom($this->ip(), 'POST', '/api/auth/register', content: [
            'name' => 'Vera Example',
            'email' => $address,
            'password' => 'registration-password-1',
        ]);
        $this->assertJsonResponse($response, 201, 'data');
        $this->entityManager->clear();
        $user = $this->userRepository->findByEmail(new \App\Shared\Domain\Model\Email($address));
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }

    private function verify(string $token): Response
    {
        return $this->requestFrom($this->ip(), 'POST', '/api/auth/email/verify', content: ['token' => $token]);
    }

    private function resend(User $user): Response
    {
        $response = $this->requestFrom($this->ip(), 'POST', '/api/auth/me/email/verification', $user->getId()->toString());
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $response;
    }

    private function expectedResendBody(): string
    {
        return json_encode(['data' => ['message' => 'If your email address still needs verification, a new link has been sent.']], JSON_THROW_ON_ERROR);
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
        foreach (['domain_event_outbox', 'domain_event_outbox_delivery', 'failed_messages', 'email_verification_tokens'] as $table) {
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

    private function outstandingTokens(string $userId): int
    {
        return (int) $this->connection()->fetchOne('SELECT count(*) FROM email_verification_tokens WHERE user_id = ?', [$userId]);
    }

    private function uniqueEmail(): string
    {
        return 'verify-' . bin2hex(random_bytes(6)) . '@baander.app';
    }

    private function ip(): string
    {
        return '198.51.100.' . random_int(60, 250);
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
