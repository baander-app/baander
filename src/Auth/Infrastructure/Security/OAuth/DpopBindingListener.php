<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Auth\Infrastructure\Security\SecurityUser;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class DpopBindingListener
{
    /**
     * The routes that end a session. They accept a proof whose replay check could not run
     * because the jti store was unavailable: a replayed logout can only re-end the session
     * its access token belongs to, and a replayed revoke reaches at most another chain of
     * the same user whose token id the replayer already knows. Every other route keeps the
     * fail-closed 503, and these routes keep every other verdict.
     */
    private const array REVOCATION_ROUTES = ['auth_logout', 'oauth_revoke'];

    /**
     * The video delivery routes whose URL signature stands in for the DPoP proof: a GET to
     * one of them skips the proof check, and the controller validates the signature instead.
     * Session controls and playback initiation keep the proof check. The firewall grants these
     * routes public access and the API rate limiter exempts them; a test holds the three lists
     * to the compiled routes.
     *
     * @var list<string>
     */
    public const array SIGNED_DELIVERY_ROUTES = [
        'stream_master_manifest',
        'stream_media_manifest',
        'stream_dash_manifest',
        'stream_subtitle_manifest',
        'stream_segment_init_segment',
        'stream_segment_segment',
        'stream_segment_subtitle_segment',
    ];

    private Parser $parser;

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly DpopProofValidator $dpopProofValidator,
        private readonly LoggerInterface $logger,
    ) {
        $this->parser = new Parser(new JoseEncoder());
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: -5)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $securityToken = $this->tokenStorage->getToken();
        if ($securityToken === null) {
            return;
        }

        $user = $securityToken->getUser();
        if (!($user instanceof SecurityUser)) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        if ($request->isMethod('GET') && in_array($route, self::SIGNED_DELIVERY_ROUTES, true)) {
            return;
        }

        $authHeader = $request->headers->get('Authorization', '');
        $accessToken = null;

        if (str_starts_with($authHeader, 'DPoP ')) {
            $accessToken = substr($authHeader, 5);
        } elseif (str_starts_with($authHeader, 'Bearer ')) {
            $accessToken = substr($authHeader, 7);
        }

        if ($accessToken === null || $accessToken === '') {
            return;
        }

        $expectedJkt = $this->extractJktFromAccessToken($accessToken);

        if ($expectedJkt === null) {
            $this->logger->warning('DPoP binding check failed: access token missing cnf.jkt claim.');

            throw new AccessDeniedException('Access token is not DPoP-bound.');
        }

        $dpopHeader = $request->headers->get('DPoP');
        if ($dpopHeader === null || $dpopHeader === '') {
            throw new AccessDeniedException('DPoP proof header is required.');
        }

        $revocationRoute = is_string($route) && in_array($route, self::REVOCATION_ROUTES, true);

        try {
            $result = $this->dpopProofValidator->validate(
                dpopJwt: $dpopHeader,
                request: $request,
                accessToken: $accessToken,
                expectedJkt: $expectedJkt,
                skipReplayCheckOnStoreFailure: $revocationRoute,
            );
        } catch (DpopStoreUnavailableException) {
            // The replay check could not run, so the proof is neither accepted nor refused:
            // the request waits (503), it is not denied. The jti cache logged the failure.
            $event->setResponse(DpopStoreUnavailableResponse::create());

            return;
        }

        if (!$result->isValid()) {
            $this->logger->warning('DPoP proof validation failed.', [
                'error' => $result->getError(),
                'description' => $result->getErrorDescription(),
            ]);

            throw new AccessDeniedException($result->getErrorDescription() ?? $result->getError() ?? 'DPoP proof validation failed.');
        }

        if ($result->isReplayUnchecked()) {
            // Neither the token nor the proof is logged: the thumbprint and the user name the key holder.
            $this->logger->warning('DPoP proof accepted without the replay check: the jti store is unavailable and the route ends a session.', [
                'route' => $route,
                'jkt' => $result->getJkt(),
                'user_id' => $user->getId(),
            ]);
        }
    }

    private function extractJktFromAccessToken(string $jwt): ?string
    {
        try {
            $token = $this->parser->parse($jwt);
        } catch (\Throwable) {
            return null;
        }

        if (!$token instanceof UnencryptedToken) {
            return null;
        }
        $cnf = $token->claims()->get('cnf');
        if (!is_array($cnf)) {
            return null;
        }

        $jkt = $cnf['jkt'] ?? null;

        return is_string($jkt) ? $jkt : null;
    }
}
