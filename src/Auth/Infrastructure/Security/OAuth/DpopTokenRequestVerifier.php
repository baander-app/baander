<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks the DPoP proof (RFC 9449) that every token-issuing request must carry.
 *
 * Passkey login accepts only a proof that carries a server-issued nonce and is
 * valid for the request, the same checks password login and refresh apply.
 * The issued tokens are bound to the proof's key.
 */
final readonly class DpopTokenRequestVerifier
{
    public function __construct(
        private DpopProofValidator $proofValidator,
        private DpopNonceManager $nonceManager,
    ) {
    }

    /**
     * @return string|JsonResponse the proof key's JWK thumbprint, or the response to send instead of tokens
     */
    public function verify(Request $request): string|JsonResponse
    {
        $proof = $request->headers->get('DPoP');
        if ($proof === null || $proof === '') {
            return new JsonResponse(
                ['error' => ['message' => 'DPoP proof header is required.', 'code' => Response::HTTP_BAD_REQUEST]],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // Nonce challenge-response: the proof must carry a nonce this server issued.
        $nonce = $this->proofValidator->extractNonce($proof);
        if ($nonce === null || $nonce === '') {
            return $this->nonceManager->createChallengeResponse();
        }
        if (!$this->nonceManager->isValid($nonce)) {
            return $this->nonceManager->createChallengeResponse('Authorization server requires a fresh nonce in DPoP proof.');
        }

        $result = $this->proofValidator->validate($proof, $request);
        $jkt = $result->getJkt();
        if (!$result->isValid() || $jkt === null) {
            return $this->nonceManager->createChallengeResponse(
                $result->getErrorDescription() ?? $result->getError() ?? 'DPoP proof validation failed.',
            );
        }

        return $jkt;
    }

    /** Issues the nonce the client must put in its next proof, such as the one it refreshes with. */
    public function issueNonce(): string
    {
        $nonce = $this->nonceManager->generateNonce();
        $this->nonceManager->storeNonce($nonce);

        return $nonce;
    }
}
