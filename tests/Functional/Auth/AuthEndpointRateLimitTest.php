<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Symfony\Component\HttpFoundation\Response;

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

    public function testOAuthTokenEndpointIsLimitedPerIpBeforeTheClientAuthenticates(): void
    {
        $this->exhaust('oauth_token_ip', '198.51.100.60');

        $this->assertTooManyRequests($this->requestFrom('198.51.100.60', 'POST', '/api/oauth/token', content: ['grant_type' => 'refresh_token', 'client_id' => 'rate_limit_client_0001']));
    }

    public function testTheTokenEndpointChargesAnAuthenticatedPublicClientPerCallerNetwork(): void
    {
        $client = Client::registerPublic('Rate limited player', ['https://player.baander.app/callback']);
        static::getContainer()->get(ClientRepositoryInterface::class)->saveClient($client);
        $clientId = $client->getPublicId()->toString();
        $this->exhaust('oauth_token_client', $clientId . '|198.51.100.0');
        $this->trackLimiter('oauth_token_client', $clientId . '|203.0.113.0');

        $this->assertTooManyRequests($this->refreshGrantFrom('198.51.100.61', $clientId));

        // Callers on another network still reach the grant, which refuses the unknown token.
        $other = $this->refreshGrantFrom('203.0.113.61', $clientId);
        self::assertSame(400, $other->getStatusCode(), (string) $other->getContent());
        self::assertSame('invalid_grant', json_decode((string) $other->getContent(), true, flags: JSON_THROW_ON_ERROR)['error']);
    }

    public function testTheRefreshLimiterIsKeyedOnTheHashOfThePresentedToken(): void
    {
        $token = 'refresh-limit-' . bin2hex(random_bytes(16));
        $this->trackLimiter('auth_refresh_client', hash('sha256', $token));
        $this->trackLimiter('auth_refresh_client', $token);

        $this->requestFrom('198.51.100.67', 'POST', '/api/auth/refresh', content: ['refreshToken' => $token]);

        $remaining = fn (string $key): int => $this->limiter('auth_refresh_client')->create($key)->consume(0)->getRemainingTokens();
        self::assertSame(9999, $remaining(hash('sha256', $token)));
        self::assertSame(10000, $remaining($token), 'No bucket is keyed on the raw token.');
    }

    private function refreshGrantFrom(string $ip, string $clientId): Response
    {
        $key = new SignedDpopProof();
        $challenge = $this->requestFrom($ip, 'POST', '/api/oauth/token', content: ['grant_type' => 'refresh_token', 'client_id' => $clientId]);
        $nonce = $challenge->headers->get('DPoP-Nonce');
        self::assertIsString($nonce, (string) $challenge->getContent());

        $this->client->request('POST', '/api/oauth/token', [], [], [
            'REMOTE_ADDR' => $ip,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_DPOP' => $key->createWithNonce('POST', 'http://localhost/api/oauth/token', $nonce),
        ], json_encode(['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => str_repeat('r', 80)], JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    public function testPushedAuthorizationRequestsAreLimitedPerIp(): void
    {
        $this->exhaust('oauth_par_ip', '198.51.100.66');

        $this->assertTooManyRequests($this->requestFrom('198.51.100.66', 'POST', '/api/oauth/par', content: ['client_id' => 'rate_limit_client_0006']));
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

        $this->assertTooManyRequests($this->requestFrom('198.51.100.64', 'GET', '/api/oauth/authorize?client_id=rate_limit_client_0005&request_uri=urn:ietf:params:oauth:request_uri:unknown', $user->getId()->toString()));
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
