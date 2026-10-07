<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

final class AuthEndpointRateLimitTest extends RateLimitTestCase
{
    public function testPasswordResetUnderTheAccountLimitIssuesAToken(): void
    {
        $user = $this->createTestUser();

        $response = $this->requestFrom('198.51.100.40', 'POST', '/api/auth/password/reset-request', content: ['email' => $user->getEmail()]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertTrue($this->hasResetToken($user->getId()->toString()));
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
        self::assertFalse($this->hasResetToken($user->getId()->toString()), 'No token may be issued over the account limit.');
    }

    public function testPasswordResetStillLimitsPerIp(): void
    {
        $this->exhaust('auth_password_reset_ip', '198.51.100.42');

        $this->assertTooManyRequests($this->requestFrom('198.51.100.42', 'POST', '/api/auth/password/reset-request', content: ['email' => 'someone@baander.app']));
    }

    public function testPasswordResetRedemptionSharesThePerIpLimit(): void
    {
        $this->exhaust('auth_password_reset_ip', '198.51.100.43');

        $this->assertTooManyRequests($this->requestFrom('198.51.100.43', 'POST', '/api/auth/password/reset', content: ['token' => 'guess', 'password' => 'guessed-password']));
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

    public function testOAuthTokenEndpointIsLimitedPerIpAndPerClient(): void
    {
        $this->exhaust('oauth_token_ip', '198.51.100.60');
        $this->assertTooManyRequests($this->requestFrom('198.51.100.60', 'POST', '/api/oauth/token', content: ['grant_type' => 'refresh_token', 'client_id' => 'rate_limit_client_0001']));

        $this->exhaust('oauth_token_client', 'rate_limit_client_0002');
        $this->assertTooManyRequests($this->requestFrom('198.51.100.61', 'POST', '/api/oauth/token', content: ['grant_type' => 'refresh_token', 'client_id' => 'rate_limit_client_0002']));

        // Another address and client still reach the endpoint, which then asks for a DPoP proof.
        $other = $this->requestFrom('198.51.100.62', 'POST', '/api/oauth/token', content: ['grant_type' => 'refresh_token', 'client_id' => 'rate_limit_client_0003']);
        self::assertSame(400, $other->getStatusCode(), (string) $other->getContent());
    }

    public function testDeviceAuthorizationIsLimitedPerIp(): void
    {
        $this->exhaust('oauth_device_authorize_ip', '198.51.100.63');

        $this->assertTooManyRequests($this->requestFrom('198.51.100.63', 'POST', '/api/oauth/device/authorize', content: ['clientId' => 'rate_limit_client_0004']));
    }

    public function testAuthorizationAndUserCodeEndpointsAreLimitedPerIp(): void
    {
        $user = $this->createTestUser();
        $this->exhaust('oauth_authorize_ip', '198.51.100.64');
        $this->exhaust('oauth_device_verify_ip', '198.51.100.64');

        $this->assertTooManyRequests($this->requestFrom('198.51.100.64', 'GET', '/api/oauth/authorize?response_type=code&client_id=rate_limit_client_0005', $user->getId()->toString()));
        $this->assertTooManyRequests($this->requestFrom('198.51.100.64', 'GET', '/api/oauth/device/verify?user_code=BCDF-GHJK', $user->getId()->toString()));
        $this->assertTooManyRequests($this->requestFrom('198.51.100.64', 'POST', '/api/oauth/device/approve', $user->getId()->toString(), ['userCode' => 'BCDF-GHJK', 'action' => 'approve']));

        $lookup = $this->requestFrom('198.51.100.65', 'GET', '/api/oauth/device/verify?user_code=BCDF-GHJK', $user->getId()->toString());
        self::assertSame(400, $lookup->getStatusCode(), (string) $lookup->getContent());
    }

    private function hasResetToken(string $userId): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM password_reset_tokens WHERE user_id = ?)',
            [$userId],
        );
    }
}
