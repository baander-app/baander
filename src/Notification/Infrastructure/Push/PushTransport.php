<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Push;

use App\Shared\Infrastructure\Http\PublicDestinationPolicy;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Enforces the destination of the encrypted request produced by Minishlink. */
final class PushTransport
{
    /** @var \Closure(RequestInterface, array<string, mixed>): PromiseInterface */
    private readonly \Closure $handler;

    /**
     * @param list<string> $allowedPushDomains
     * @param (\Closure(RequestInterface, array<string, mixed>): PromiseInterface)|null $handler terminal transport for isolated tests
     */
    public function __construct(
        private readonly PublicDestinationPolicy $destinations,
        private readonly array $allowedPushDomains,
        ?\Closure $handler = null,
    ) {
        $this->handler = $handler ?? (new CurlMultiHandler())(...);
    }

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        try {
            $deadline = hrtime(true) / 1e9 + 30;
            $safe = $this->destinations->resolve((string) $request->getUri(), [], $this->allowedPushDomains, $deadline);
            $remaining = $deadline - hrtime(true) / 1e9;
            if ($remaining <= 0) {
                throw new \RuntimeException('Push request timed out.');
            }
            $ip = $safe['ips'][0];
            $address = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
            $options['curl'] = [
                CURLOPT_RESOLVE => [$safe['host'] . ':443:' . $address],
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true,
            ];
            $options['proxy'] = '';
            $options['allow_redirects'] = false;
            $options['verify'] = true;
            $options['timeout'] = $remaining;
            $options['connect_timeout'] = min(5.0, $remaining);
            // CurlFactory's installed rewind path rejects at 2, before calling its handler.
            $options['_curl_retries'] = 2;
            $options['debug'] = false;

            return ($this->handler)($request, $options)->then(
                static function (ResponseInterface $response) use ($request): ResponseInterface {
                    if ($response->getStatusCode() >= 300 && $response->getStatusCode() < 400) {
                        throw new RequestException('Push redirect refused.', $request);
                    }
                    if ($response->getStatusCode() >= 400) {
                        // Retain only the response needed for normal 404/410 expiry handling.
                        throw new RequestException('Push service rejected notification.', $request, $response);
                    }

                    return $response;
                },
                static fn (): never => throw new RequestException('Push transport failed.', $request),
            );
        } catch (\Throwable) {
            return new RejectedPromise(new RequestException('Push destination could not be contacted safely.', $request));
        }
    }
}
