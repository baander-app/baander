<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Application\Exception\RateLimiterUnavailableException;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RateLimiterHttpAvailabilityTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, bool, array<string, mixed>}> */
    public static function endpoints(): iterable
    {
        yield 'anonymous API' => ['anonymous_api', 'GET', '/api/auth/me', false, []];
        yield 'authenticated API' => ['authenticated_api', 'GET', '/api/auth/me', true, []];
        yield 'login pre-auth' => ['auth_login_ip', 'POST', '/api/auth/login', false, ['email' => 'user@baander.app', 'password' => 'secret']];
        yield 'password reset pre-auth' => ['auth_password_reset_ip', 'POST', '/api/auth/password/reset-request', false, ['email' => 'user@baander.app']];
        yield 'OAuth pre-auth' => ['oauth_token_ip', 'POST', '/api/oauth/token', false, ['grant_type' => 'refresh_token']];
        yield 'Discovery' => ['discovery_endpoint_ip', 'POST', '/api/discovery/register', true, []];
        yield 'cover extraction' => ['batch_cover_extract', 'POST', '/api/albums/covers/extract', true, []];
        yield 'configuration check' => ['config_check', 'GET', '/api/debug/config-check', true, []];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('endpoints')]
    public function testAConsumerCannotProceedWhenItsBudgetStoreIsUnavailable(string $limiter, string $method, string $uri, bool $authenticated, array $body): void
    {
        $user = $authenticated ? $this->createAdminUser() : null;
        $this->failLimiter($limiter);
        $async = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $before = count($async->getSent());

        $response = $user === null
            ? $this->anonymousRequest($method, $uri, $body)
            : $this->authenticatedRequest($method, $uri, $user, $body);

        $this->assertUnavailable($response);
        self::assertCount($before, $async->getSent(), 'Unavailable budget must not dispatch protected work.');
    }

    public function testPasswordResetAccountBudgetOutageIsNotAnAcceptedRequestAndIssuesNoToken(): void
    {
        $user = $this->createTestUser();
        $this->failLimiter('auth_password_reset_email');

        $response = $this->anonymousRequest('POST', '/api/auth/password/reset-request', ['email' => $user->getEmail()]);

        $this->assertUnavailable($response);
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = ?', [$user->getId()->toString()],
        ));
        $unknown = $this->anonymousRequest('POST', '/api/auth/password/reset-request', ['email' => 'unknown-' . bin2hex(random_bytes(8)) . '@baander.app']);
        $this->assertUnavailable($unknown);
        self::assertSame($response->getContent(), $unknown->getContent(), 'Known and unknown addresses have the same outage response.');
    }

    public function testVerificationResendBudgetOutageIssuesNoVerificationToken(): void
    {
        $user = $this->createTestUser();
        $this->failLimiter('auth_email_verification_user');
        $before = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM email_verification_tokens WHERE user_id = ?', [$user->getId()->toString()]);

        $response = $this->authenticatedRequest('POST', '/api/auth/me/email/verification', $user);

        $this->assertUnavailable($response);
        self::assertSame($before, $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM email_verification_tokens WHERE user_id = ?', [$user->getId()->toString()]));
    }

    private function failLimiter(string $name): void
    {
        // Fail at consume, after the actual consumer has resolved its production key.
        $limiter = $this->createStub(LimiterInterface::class);
        $limiter->method('consume')->willThrowException(new RateLimiterUnavailableException());
        $factory = $this->createStub(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturn($limiter);
        static::getContainer()->set('limiter.' . $name, $factory);
    }

    private function assertUnavailable(\Symfony\Component\HttpFoundation\Response $response): void
    {
        self::assertSame(503, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame(['error' => ['message' => (new RateLimiterUnavailableException())->getMessage(), 'code' => 503]], json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }
}
