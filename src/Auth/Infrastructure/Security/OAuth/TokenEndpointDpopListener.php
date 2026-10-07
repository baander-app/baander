<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\DTO\VerifiedDpopProof;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Requires a nonce-bearing DPoP proof (RFC 9449) on every OAuth token endpoint request.
 *
 * A missing, stale or invalid proof is answered here, before any grant runs, with
 * an OAuth error: `use_dpop_nonce` when the proof lacks a fresh server nonce,
 * `invalid_dpop_proof` when the proof is missing or invalid. Each answer carries
 * a fresh nonce in the DPoP-Nonce header. A valid proof is stored on the request
 * as a VerifiedDpopProof; the token endpoint binds the issued tokens to its key
 * and returns its next nonce with the answer, success or OAuth error alike,
 * because a device polls repeatedly and each nonce is accepted once.
 *
 * Priority 6 runs after routing (32), the auth rate limits (10), the firewall (8)
 * and the generic API limit (7).
 */
final readonly class TokenEndpointDpopListener
{
    public const string ROUTE = 'oauth_token';

    public function __construct(
        private DpopTokenRequestVerifier $verifier,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->attributes->get('_route') !== self::ROUTE) {
            return;
        }

        $result = $this->verifier->inspect($request);
        if ($result instanceof DpopProofRejection) {
            $event->setResponse(new JsonResponse(
                ['error' => $result->oauthError(), 'error_description' => $result->description],
                Response::HTTP_BAD_REQUEST,
                [
                    'DPoP-Nonce' => $this->verifier->issueNonce(),
                    'Cache-Control' => 'no-store',
                    'Pragma' => 'no-cache',
                ],
            ));

            return;
        }

        $request->attributes->set(VerifiedDpopProof::class, new VerifiedDpopProof($result, $this->verifier->issueNonce()));
    }
}
