<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientKeySource;
use Baander\HttpClient\PublicHttpClient;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Fetches the JWK Set a client publishes at its `jwks_uri`.
 *
 * The request goes only to the URI's own host, over https on port 443, to a public
 * address, without following redirects, and within three seconds. The body is read
 * only up to the size an inline key set may have; a larger one is cut off.
 */
final readonly class ClientJwksFetcher
{
    public const int TIMEOUT_SECONDS = 3;

    public function __construct(
        private PublicHttpClient $httpClient,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return array{keys: list<array<string, mixed>>}|null The validated key set; null when the
     *                                                      answer is not a valid set of public keys
     *
     * @throws DpopStoreUnavailableException when the set could not be fetched: a refused
     *                                       destination, a transport failure or timeout, an
     *                                       answer other than 200, or a body over the cap
     */
    public function fetch(string $jwksUri): ?array
    {
        try {
            $body = $this->download($jwksUri);
        } catch (Throwable $e) {
            // Transport messages carry the URI, which may hold a token; only the host is kept.
            $this->logger->warning('A client key set could not be fetched from {host}', [
                'host' => parse_url($jwksUri, PHP_URL_HOST),
                'failure' => $e::class,
            ]);

            throw DpopStoreUnavailableException::clientKeySetFetch();
        }

        try {
            $set = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

            return is_array($set) ? ClientKeySource::fromJwks($set)->jwks() : null;
        } catch (JsonException | InvalidArgumentException) {
            return null;
        }
    }

    private function download(string $jwksUri): string
    {
        $host = strtolower((string) parse_url($jwksUri, PHP_URL_HOST));
        $response = $this->httpClient->request('GET', $jwksUri, [$host], [
            'headers' => ['Accept' => 'application/jwk-set+json, application/json'],
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
        ]);
        if ($response->getStatusCode() !== 200) {
            $response->cancel();

            throw new \RuntimeException('The key set answer was not 200.');
        }

        return $this->httpClient->content($response, ClientKeySource::MAX_JWKS_BYTES);
    }
}
