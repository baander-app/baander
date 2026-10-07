<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;

final class AuthEndpointRateLimitTest extends RateLimitTestCase
{
    public function testPasswordResetUnderTheAccountLimitIssuesAToken(): void
    {
        $user = $this->createTestUser();

        $response = $this->requestFrom('198.51.100.40', 'POST', '/api/auth/password/reset-request', content: ['email' => $user->getEmail()]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertNotNull($this->tokens()->findByEmail($user->getEmail()));
    }

    public function testPasswordResetOverTheAccountLimitAnswersLikeAnUnknownAddress(): void
    {
        $user = $this->createTestUser();
        $this->exhaust('auth_password_reset_email', $user->getEmail());

        // Case variants normalize to the same account bucket.
        $limited = $this->requestFrom('198.51.100.41', 'POST', '/api/auth/password/reset-request', content: ['email' => strtoupper($user->getEmail())]);
        $unknown = $this->requestFrom('198.51.100.41', 'POST', '/api/auth/password/reset-request', content: ['email' => 'nobody-' . bin2hex(random_bytes(4)) . '@baander.app']);

        self::assertSame(200, $limited->getStatusCode(), (string) $limited->getContent());
        self::assertSame(200, $unknown->getStatusCode(), (string) $unknown->getContent());
        self::assertSame($unknown->getContent(), $limited->getContent());
        self::assertNull($limited->headers->get('Retry-After'));
        self::assertNull($this->tokens()->findByEmail($user->getEmail()), 'No token may be issued over the account limit.');
    }

    public function testPasswordResetStillLimitsPerIp(): void
    {
        $this->exhaust('auth_password_reset_ip', '198.51.100.42');

        $this->assertTooManyRequests($this->requestFrom('198.51.100.42', 'POST', '/api/auth/password/reset-request', content: ['email' => 'someone@baander.app']));
    }

    public function testPasskeySignInIsLimitedPerIp(): void
    {
        $this->exhaust('auth_passkey_ip', '198.51.100.50');

        $this->assertTooManyRequests($this->requestFrom('198.51.100.50', 'POST', '/api/auth/passkey/authenticate/options', content: []));
        $this->assertTooManyRequests($this->requestFrom('198.51.100.50', 'POST', '/api/auth/passkey/authenticate', content: ['challengeKey' => 'key', 'response' => ['id' => 'x']]));
        $this->assertTooManyRequests($this->requestFrom('198.51.100.50', 'POST', '/api/auth/login/passkey', content: ['challengeKey' => 'key', 'response' => ['id' => 'x']]));

        $other = $this->requestFrom('198.51.100.51', 'POST', '/api/auth/passkey/authenticate/options', content: []);
        self::assertSame(200, $other->getStatusCode(), (string) $other->getContent());
    }

    private function tokens(): PasswordResetTokenRepositoryInterface
    {
        $repository = static::getContainer()->get(PasswordResetTokenRepositoryInterface::class);
        self::assertInstanceOf(PasswordResetTokenRepositoryInterface::class, $repository);

        return $repository;
    }
}
