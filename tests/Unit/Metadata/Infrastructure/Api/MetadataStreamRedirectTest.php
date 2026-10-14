<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Api;

use App\Tests\Support\Outbound\LocalTlsServer;
use PHPUnit\Framework\TestCase;

final class MetadataStreamRedirectTest extends TestCase
{
    /** Records the stream transport's current gap, rather than certifying an adapter's policy. */
    public function testStreamDefaultsAllowPrivateDowngrade(): void
    {
        $server = new LocalTlsServer();
        try {
            $context = stream_context_create([
                'http' => ['header' => 'Authorization: Bearer baander-local-canary', 'timeout' => 2],
                'ssl' => ['cafile' => $server->certificate, 'peer_name' => 'outbound.baander.app'],
            ]);
            self::assertSame('[]', file_get_contents('https://127.0.0.1:' . $server->tlsPort . '/stream-redirect', context: $context));
            $requests = $server->requests();
            self::assertSame([
                'https /stream-redirect authorization=Bearer baander-local-canary',
                'http /private',
            ], [
                $requests[0],
                explode(' authorization=', $requests[1])[0],
            ]);
            // PHP 8.5.2 in the CI image forwards Authorization to this HTTP hop;
            // PHP 8.5.11 strips it. Both follow the forbidden destination.
            if (PHP_VERSION_ID === 80502) {
                self::assertSame('http /private authorization=Bearer baander-local-canary', $requests[1]);
            }
        } finally {
            $server->close();
        }
    }
}
