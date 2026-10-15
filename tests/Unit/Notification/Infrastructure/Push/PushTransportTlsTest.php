<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Push;

use App\Notification\Infrastructure\Push\PushTransport;
use App\Notification\Infrastructure\Push\WebPushFactory;
use App\Shared\Infrastructure\Http\PublicDestinationPolicy;
use App\Tests\Support\Outbound\ExpectedWebPushConstruction;
use App\Tests\Support\Outbound\LocalTlsServer;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise\PromiseInterface;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class PushTransportTlsTest extends TestCase
{
    private LocalTlsServer $server;

    protected function setUp(): void
    {
        $this->server = new LocalTlsServer();
    }

    protected function tearDown(): void
    {
        $this->server->close();
    }

    public function testRealCurlTransportPreservesHostnameAndTrustsOnlyTheConfiguredCertificate(): void
    {
        self::assertTrue($this->send('https://outbound.baander.app/ok')->isSuccess());
        self::assertCount(1, $this->server->requests());
        self::assertStringStartsWith('https /ok authorization=vapid ', $this->server->requests()[0]);
    }

    public function testUntrustedCertificateFailsBeforeSendingHttp(): void
    {
        $report = $this->send('https://outbound.baander.app/ok', false);

        self::assertFalse($report->isSuccess());
        self::assertFalse($report->isSubscriptionExpired());
        self::assertSame([], $this->server->requests());
    }

    public function testCertificateHostnameMismatchFailsBeforeSendingHttp(): void
    {
        $report = $this->send('https://mismatch.baander.app/ok');

        self::assertFalse($report->isSuccess());
        self::assertFalse($report->isSubscriptionExpired());
        self::assertSame([], $this->server->requests());
    }

    public function testRealRedirectIsAFailureAndNeverContactsThePrivatePeer(): void
    {
        $report = $this->send('https://outbound.baander.app/stream-redirect');

        self::assertFalse($report->isSuccess());
        self::assertFalse($report->isSubscriptionExpired());
        self::assertCount(1, $this->server->requests());
        self::assertStringStartsWith('https /stream-redirect authorization=vapid ', $this->server->requests()[0]);
    }

    private function send(string $url, bool $trust = true): MessageSentReport
    {
        $curl = new CurlMultiHandler();
        $captured = null;
        // Capture production options before routing solely inside this fixture.
        $handler = function (RequestInterface $request, array $options) use ($curl, $trust, &$captured): PromiseInterface {
            $host = $request->getUri()->getHost();
            $captured = $options;
            $options['curl'][CURLOPT_CONNECT_TO] = [$host . ':443:127.0.0.1:' . $this->server->tlsPort];
            if ($trust) {
                $options['curl'][CURLOPT_CAINFO] = $this->server->certificate;
            }

            return $curl($request, $options);
        };
        $transport = new PushTransport(new PublicDestinationPolicy(static fn (): array => ['1.1.1.1']), ['baander.app'], $handler);
        $keys = VAPID::createVapidKeys();
        $webPush = ExpectedWebPushConstruction::create(new WebPushFactory($transport), ['VAPID' => ['subject' => 'mailto:push@baander.app', ...$keys]]);
        $subscription = Subscription::create([
            'endpoint' => $url,
            'keys' => ['p256dh' => $keys['publicKey'], 'auth' => rtrim(strtr(base64_encode(str_repeat('a', 16)), '+/', '-_'), '=')],
            'contentEncoding' => 'aes128gcm',
        ]);

        $report = $webPush->sendOneNotification($subscription, 'confidential-payload');
        self::assertNotNull($captured);
        $host = (string) parse_url($url, PHP_URL_HOST);
        self::assertSame([$host . ':443:1.1.1.1'], $captured['curl'][CURLOPT_RESOLVE]);
        self::assertTrue($captured['curl'][CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $captured['curl'][CURLOPT_SSL_VERIFYHOST]);
        self::assertFalse($captured['curl'][CURLOPT_FOLLOWLOCATION]);
        self::assertSame('', $captured['curl'][CURLOPT_PROXY]);
        self::assertSame(2, $captured['_curl_retries']);

        return $report;
    }
}
