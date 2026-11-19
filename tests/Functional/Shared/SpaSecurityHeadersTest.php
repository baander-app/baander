<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

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
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }
}
