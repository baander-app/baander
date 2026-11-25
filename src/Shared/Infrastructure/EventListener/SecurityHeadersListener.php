<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener;

use App\Shared\Application\Http\PublicOrigins;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Browser protections for every application response. HTML responses get a
 * Content-Security-Policy whose script nonce is fresh per request and issued only here:
 * templates read it from the request attribute named by NONCE_ATTRIBUTE. Every other response
 * gets a policy that refuses to act as a document. HTTPS requests, including HTTPS through a
 * trusted proxy, get HSTS.
 *
 * The policy follows what the web app loads: its own scripts, WebAssembly DSP modules and
 * audio worklets, the stream service worker and blob: media workers, a WebSocket to the public
 * origin the page names as its API URL, signed media on the primary public origin, and radio
 * streams and station logos from any host. Forms post only to the page's own origin. Styles
 * allow inline because libraries and the development server inject style tags.
 */
final readonly class SecurityHeadersListener
{
    public const string NONCE_ATTRIBUTE = '_csp_nonce';

    private const array HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'X-XSS-Protection'       => '0',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=()',
    ];

    private const string HSTS = 'max-age=31536000';

    /**
     * For every response that is not the app page, unless its controller set its own policy: a
     * JSON body, a manifest or a file opened as a page loads nothing, runs no script and cannot
     * be framed.
     */
    private const string DOCUMENT_REFUSED = "default-src 'none'; frame-ancestors 'none'; sandbox";

    /**
     * @param list<string> $developmentOrigins Origins of a development asset server, empty in production
     */
    public function __construct(
        private PublicOrigins $publicOrigins,
        private array $developmentOrigins = [],
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getRequest()->attributes->set(self::NONCE_ATTRIBUTE, random_bytes(18) |> base64_encode(...));
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        foreach (self::HEADERS as $key => $value) {
            if (!$response->headers->has($key)) {
                $response->headers->set($key, $value);
            }
        }

        if ($request->isSecure() && !$response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', self::HSTS);
        }

        if ($response->headers->has('Content-Security-Policy')) {
            return;
        }
        if (!self::isHtml($response)) {
            $response->headers->set('Content-Security-Policy', self::DOCUMENT_REFUSED);

            return;
        }
        $nonce = $request->attributes->get(self::NONCE_ATTRIBUTE);
        if (is_string($nonce)) {
            $response->headers->set('Content-Security-Policy', $this->policy($request, $nonce));
        }
    }

    private function policy(Request $request, string $nonce): string
    {
        $development = array_map(self::origin(...), $this->developmentOrigins) |> array_filter(...) |> array_values(...);
        // A page served over HTTPS admits no plain-HTTP station stream or logo: that would be
        // mixed content a network attacker could replace.
        $stations = $request->isSecure() ? ['https:'] : ['https:', 'http:'];

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-" . $nonce . "'", "'wasm-unsafe-eval'", ...$development],
            'style-src' => ["'self'", "'unsafe-inline'", ...$development],
            'img-src' => ["'self'", 'data:', 'blob:', ...$stations],
            'media-src' => ["'self'", 'blob:', ...$stations],
            'font-src' => ["'self'", 'data:', ...$development],
            'connect-src' => [
                "'self'",
                self::socketOrigin($this->publicOrigins->forRequest($request)),
                $this->publicOrigins->primary,
                ...$development,
                ...array_map(self::socketOrigin(...), $development),
            ],
            'worker-src' => ["'self'", 'blob:'],
            'manifest-src' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'none'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ];

        return implode('; ', array_map(
            static fn (string $name, array $sources): string => $name . ' ' . implode(' ', array_unique($sources)),
            array_keys($directives),
            $directives,
        ));
    }

    private static function isHtml(Response $response): bool
    {
        $type = $response->headers->get('Content-Type');

        return $type === null || str_starts_with(strtolower($type), 'text/html');
    }

    private static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['http', 'https'], true)) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private static function socketOrigin(string $origin): string
    {
        return preg_replace('/^http/', 'ws', $origin, 1) ?? $origin;
    }
}
