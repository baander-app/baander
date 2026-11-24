<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Api;

use App\Tests\Support\Outbound\LocalTlsServer;
use PHPUnit\Framework\TestCase;

final class MetadataStreamRedirectTest extends TestCase
{
    /**
     * PHP's stream transport followed a redirect from this HTTPS peer to a private HTTP
     * address. allow_url_fopen is off in every image, so PHP selects no wrapper for a URL
     * and the peer never hears from a file function.
     */
    public function testFileFunctionsOpenNoUrlSoThePeerIsNeverContacted(): void
    {
        $server = new LocalTlsServer();
        try {
            $context = stream_context_create([
                'http' => ['header' => 'Authorization: Bearer baander-local-canary', 'timeout' => 2],
                'ssl' => ['cafile' => $server->certificate, 'peer_name' => 'outbound.baander.app'],
            ]);
            $urls = [
                'https://127.0.0.1:' . $server->tlsPort . '/stream-redirect',
                'http://127.0.0.1:' . $server->httpPort . '/private',
                'ftp://127.0.0.1:' . $server->httpPort . '/private',
            ];
            foreach ($urls as $url) {
                error_clear_last();
                self::assertFalse(@file_get_contents($url, context: $context), $url);
                self::assertStringContainsString('no suitable wrapper could be found', error_get_last()['message'] ?? '', $url);
            }
            self::assertSame([], $server->requests());
        } finally {
            $server->close();
        }
    }
}
