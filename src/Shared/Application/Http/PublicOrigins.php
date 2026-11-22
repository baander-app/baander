<?php

declare(strict_types=1);

namespace App\Shared\Application\Http;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

/**
 * The origins the deployment answers on: the origin of the public URL (APP_URL) and each further
 * origin the operator lists (AUTH_EXTRA_ORIGINS), such as a LAN address. An origin is
 * `scheme://host[:port]` with the scheme and host in lowercase and the default port left out.
 * The request's own scheme and Host never add to it: behind a TLS-terminating proxy the request
 * arrives as plain HTTP, and the Host header is the client's to choose.
 */
final readonly class PublicOrigins
{
    /** The origin of the public URL. */
    public string $primary;

    /** @var list<string> The primary origin first, then the listed ones, each once */
    public array $all;

    /**
     * @param string $publicUrl The URL users reach the deployment at
     * @param list<string> $extraOrigins Further origins; blank entries are skipped
     *
     * @throws InvalidArgumentException when a URL is not an absolute http or https URL
     */
    public function __construct(string $publicUrl, array $extraOrigins = [])
    {
        $this->primary = $publicUrl |> trim(...) |> self::origin(...);
        $this->all = $extraOrigins
            |> (static fn (array $urls): array => array_map(trim(...), $urls))
            |> (static fn (array $urls): array => array_filter($urls, static fn (string $url): bool => $url !== ''))
            |> (static fn (array $urls): array => array_map(self::origin(...), $urls))
            |> (fn (array $origins): array => [$this->primary, ...$origins])
            |> array_unique(...)
            |> array_values(...);
    }

    /**
     * The origin a response renders into its URLs and policies: the request's origin when all
     * lists it, the primary origin otherwise. A trusted proxy's forwarded scheme, Host and port
     * count as the request's, so they too can only select a listed origin.
     */
    public function forRequest(Request $request): string
    {
        $origin = $request->getSchemeAndHttpHost();

        return in_array($origin, $this->all, true) ? $origin : $this->primary;
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower(is_array($parts) ? $parts['scheme'] ?? '' : '');
        $host = strtolower(is_array($parts) ? $parts['host'] ?? '' : '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidArgumentException(sprintf('The public origin "%s" is not an absolute http or https URL.', $url));
        }
        $port = $parts['port'] ?? null;
        $defaultPort = $scheme === 'https' ? 443 : 80;

        return sprintf('%s://%s%s', $scheme, $host, $port === null || $port === $defaultPort ? '' : ':' . $port);
    }
}
