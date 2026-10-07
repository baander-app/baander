<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Infrastructure\Security\ApiRateLimitListener;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Routing\RouterInterface;

final class ApiRateLimitTest extends RateLimitTestCase
{
    public function testAnonymousRequestsAreLimitedPerClientIp(): void
    {
        $this->exhaust('anonymous_api', '198.51.100.10');

        // A public endpoint reaches the request check after the firewall.
        $this->assertTooManyRequests($this->requestFrom('198.51.100.10', 'POST', '/api/auth/password/reset-request', content: ['email' => 'someone@baander.app']));
        // A protected endpoint is rejected by access control first; the rejection is charged too.
        $this->assertTooManyRequests($this->requestFrom('198.51.100.10', 'GET', '/api/auth/me'));
        $this->assertTooManyRequests($this->requestFrom('198.51.100.10', 'GET', '/api/playlists'));

        self::assertSame(401, $this->requestFrom('198.51.100.11', 'GET', '/api/playlists')->getStatusCode());
    }

    public function testRejectedCredentialsAreChargedToTheClientIp(): void
    {
        $this->exhaust('anonymous_api', '198.51.100.12');

        // The test authenticator rejects an unknown user ID, as OAuth rejects an invalid token.
        $this->assertTooManyRequests($this->requestFrom('198.51.100.12', 'GET', '/api/auth/me', Uuid::generate()->toString()));
    }

    public function testAuthenticatedRequestsAreLimitedPerUserNotPerIp(): void
    {
        $limited = $this->createTestUser();
        $other = $this->createTestUser();
        $this->exhaust('authenticated_api', $limited->getId()->toString());

        $this->assertTooManyRequests($this->requestFrom('198.51.100.20', 'GET', '/api/auth/me', $limited->getId()->toString()));
        // A second user behind the same address has an independent bucket.
        self::assertSame(200, $this->requestFrom('198.51.100.20', 'GET', '/api/auth/me', $other->getId()->toString())->getStatusCode());
    }

    public function testAuthenticatedRequestsDoNotUseTheAddressBucket(): void
    {
        $user = $this->createTestUser();
        $this->exhaust('anonymous_api', '198.51.100.21');

        self::assertSame(200, $this->requestFrom('198.51.100.21', 'GET', '/api/auth/me', $user->getId()->toString())->getStatusCode());
    }

    public function testMediaDeliveryRoutesAreExempt(): void
    {
        $user = $this->createTestUser();
        $userId = $user->getId()->toString();
        $this->exhaust('anonymous_api', '198.51.100.30');
        $this->exhaust('authenticated_api', $userId);
        $missing = Uuid::generate()->toString();

        $responses = [
            'segment' => $this->requestFrom('198.51.100.30', 'GET', '/api/transcode/' . $missing . '/segment?index=0'),
            'init segment' => $this->requestFrom('198.51.100.30', 'GET', '/api/transcode/' . $missing . '/init'),
            'media manifest' => $this->requestFrom('198.51.100.30', 'GET', '/api/transcode/' . $missing . '/media.m3u8'),
            'master manifest' => $this->requestFrom('198.51.100.30', 'GET', '/api/transcode/' . $missing . '/master.m3u8'),
            'audio stream' => $this->requestFrom('198.51.100.30', 'GET', '/api/stream/track?id=' . $missing, $userId),
            'image file' => $this->requestFrom('198.51.100.30', 'GET', '/api/images/' . $missing . '/file', $userId),
            'album cover' => $this->requestFrom('198.51.100.30', 'GET', '/api/albums/' . $missing . '/cover', $userId),
        ];

        foreach ($responses as $name => $response) {
            self::assertNotSame(429, $response->getStatusCode(), sprintf('%s must not be throttled by the generic API limiter.', $name));
        }

        // The same user is throttled on an ordinary API route.
        $this->assertTooManyRequests($this->requestFrom('198.51.100.30', 'GET', '/api/auth/me', $userId));
    }

    public function testEveryExemptRouteExistsAndServesGet(): void
    {
        $routes = static::getContainer()->get(RouterInterface::class)->getRouteCollection();

        foreach (ApiRateLimitListener::EXEMPT_ROUTES as $name) {
            $route = $routes->get($name);
            self::assertNotNull($route, sprintf('Exempt route "%s" does not exist.', $name));
            self::assertStringStartsWith('/api/', $route->getPath());
            self::assertSame(['GET'], $route->getMethods(), sprintf('Exempt route "%s" must be read-only media delivery.', $name));
        }
    }
}
