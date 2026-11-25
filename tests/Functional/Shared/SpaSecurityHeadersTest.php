<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Shared\Infrastructure\EventListener\SecurityHeadersListener;
use App\Tests\Functional\TestCase;

/** The SPA page is served with a Content-Security-Policy whose nonce admits its inline script. */
final class SpaSecurityHeadersTest extends TestCase
{
    public function testTheSpaInlineScriptCarriesThePolicyNonceAndFramingIsRefused(): void
    {
        $this->client->request('GET', '/library');
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        $policy = (string) $response->headers->get('Content-Security-Policy');
        self::assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9+\\/=]+)'/", $policy);
        preg_match("/'nonce-([A-Za-z0-9+\\/=]+)'/", $policy, $match);
        self::assertStringContainsString('<script nonce="' . $match[1] . '">', (string) $response->getContent());
        self::assertStringContainsString("frame-ancestors 'none'", $policy);
        self::assertStringContainsString("form-action 'self'", $policy);
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function testThePageNonceIsTheOneTheSecurityHeadersListenerIssued(): void
    {
        $this->client->request('GET', '/library');
        $policy = (string) $this->client->getResponse()->headers->get('Content-Security-Policy');

        self::assertSame(1, preg_match("/'nonce-([A-Za-z0-9+\\/=]+)'/", $policy, $match));
        self::assertSame($match[1], $this->client->getRequest()->attributes->get(SecurityHeadersListener::NONCE_ATTRIBUTE));
        // The listener issues 18 random bytes; a second request listener writing the same attribute
        // would replace them with a value of another shape.
        self::assertSame(18, strlen((string) base64_decode($match[1], true)));
    }
}
