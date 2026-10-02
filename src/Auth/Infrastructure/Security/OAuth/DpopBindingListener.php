<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

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

        // These delivery actions independently require a valid URL signature.
        // Session controls and playback initiation must still validate DPoP.
        if ($request->isMethod('GET') && in_array($request->attributes->get('_route'), [
            'stream_master_manifest',
            'stream_media_manifest',
            'stream_dash_manifest',
            'stream_subtitle_manifest',
            'stream_segment_init_segment',
            'stream_segment_segment',
            'stream_segment_subtitle_segment',
        ], true)) {
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

        $result = $this->dpopProofValidator->validate(
            dpopJwt: $dpopHeader,
            request: $request,
            accessToken: $accessToken,
            expectedJkt: $expectedJkt,
        );

        if (!$result->isValid()) {
            $this->logger->warning('DPoP proof validation failed.', [
                'error' => $result->getError(),
                'description' => $result->getErrorDescription(),
            ]);

            throw new AccessDeniedException($result->getErrorDescription() ?? $result->getError() ?? 'DPoP proof validation failed.');
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
