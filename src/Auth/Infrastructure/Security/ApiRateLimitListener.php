<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Enforces the generic `anonymous_api` and `authenticated_api` limiters on `/api/*`.
 *
 * A request with an authenticated user consumes `authenticated_api`, keyed by user
 * ID, so users behind one address do not share a bucket. Any other request consumes
 * `anonymous_api`, keyed by client IP. Each request is charged once, at the first
 * of these points it reaches:
 *
 * - the request check at priority 7, after the router (32) and the firewall (8),
 *   once the route and the user are known;
 * - the login-failure hook, for credentials the firewall rejects before priority 7;
 * - the access-denied hook, for requests the firewall's access control rejects
 *   before priority 7, such as an anonymous request to a protected route.
 *
 * Media delivery routes are exempt: a player fetches manifests, segments, the audio
 * stream and artwork continuously, and playback must never be throttled by the
 * generic API budget. Health and metrics endpoints live outside `/api` and are
 * never checked.
 *
 * The only per-request state is a marker attribute on the request itself, so the
 * listener is safe in long-running Swoole workers.
 */
final class ApiRateLimitListener
{
    /**
     * Route names exempt from the generic API limiters.
     *
     * @var list<string>
     */
    public const array EXEMPT_ROUTES = [
        // Audio stream; the browser issues range requests while a track plays.
        'stream_track',
        // HLS/DASH manifests; players re-fetch media playlists while playing.
        'stream_master_manifest',
        'stream_media_manifest',
        'stream_dash_manifest',
        'stream_subtitle_manifest',
        // HLS/DASH segments; one request per few seconds of video.
        'stream_segment_init_segment',
        'stream_segment_segment',
        'stream_segment_subtitle_segment',
        // Artwork bytes; a library grid loads one per visible album.
        'image_file',
        'album_cover',
    ];

    private const string CHARGED_ATTRIBUTE = '_baander_api_rate_limit_charged';

    public function __construct(
        private readonly RateLimiterFactoryInterface $anonymousApiLimiter,
        private readonly RateLimiterFactoryInterface $authenticatedApiLimiter,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly LoggerInterface $logger,
        private readonly string $environment,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->charge($event->getRequest());
        }
    }

    /**
     * The low priority lets any other failure listener run before this one throws.
     */
    #[AsEventListener(event: LoginFailureEvent::class, priority: -64)]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $this->charge($event->getRequest());
    }

    /**
     * Runs before the firewall's exception listener (priority 1) turns the denial
     * into a 401 or 403, so an exhausted bucket answers 429 instead.
     */
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 2)]
    public function onAccessDenied(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || !$event->getThrowable() instanceof AccessDeniedException) {
            return;
        }

        try {
            $this->charge($event->getRequest());
        } catch (TooManyRequestsHttpException $exception) {
            $event->setThrowable($exception);
        }
    }

    private function charge(Request $request): void
    {
        if (!$this->appliesTo($request) || $request->attributes->getBoolean(self::CHARGED_ATTRIBUTE)) {
            return;
        }
        $request->attributes->set(self::CHARGED_ATTRIBUTE, true);

        $user = $this->tokenStorage->getToken()?->getUser();
        if ($user instanceof UserInterface) {
            $this->consume($this->authenticatedApiLimiter, 'authenticated_api', $this->userKey($user), $request);

            return;
        }

        $this->consume($this->anonymousApiLimiter, 'anonymous_api', $request->getClientIp() ?? 'unknown', $request);
    }

    private function appliesTo(Request $request): bool
    {
        if ($this->environment === 'dev') {
            return false;
        }

        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return false;
        }

        $route = $request->attributes->get('_route');

        return !is_string($route) || !in_array($route, self::EXEMPT_ROUTES, true);
    }

    private function consume(RateLimiterFactoryInterface $factory, string $limiterName, string $key, Request $request): void
    {
        $result = $factory->create($key)->consume(1);
        if ($result->isAccepted()) {
            return;
        }

        $seconds = max(1, (int) ceil($result->getRetryAfter()->getTimestamp() - time()));

        $this->logger->warning('API rate limit exceeded', [
            'limiter' => $limiterName,
            'path' => $request->getPathInfo(),
            'ip' => $request->getClientIp(),
            'retry_after' => $seconds,
        ]);

        throw new TooManyRequestsHttpException($seconds, 'Too many requests. Please try again later.');
    }

    private function userKey(UserInterface $user): string
    {
        return $user instanceof AuthenticatedUserIdentityInterface ? $user->getId() : $user->getUserIdentifier();
    }
}
