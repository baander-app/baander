<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Auth\Application\Port\DpopJtiCacheInterface;
use App\Auth\Domain\Model\OAuth\ValueObject\DpopValidationResult;
use DateTimeImmutable;
use InvalidArgumentException;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final class DpopProofValidator
{
    /** One list with client assertions; the metadata test pins the advertised copy to it. */
    private const array SUPPORTED_ALGORITHMS = JwkPublicKeyConverter::SIGNING_ALGORITHMS;

    private Parser $parser;

    /** @var list<string> The origins a proof's `htu` may name, normalized and without duplicates */
    private array $publicOrigins;

    /**
     * A proof's `htu` names one of the deployment's public origins and the request path. The
     * request's own scheme and Host never count: behind a TLS-terminating proxy the request
     * reaches the application as plain HTTP, and the Host header is the client's to choose.
     *
     * @param string $publicUrl The URL users reach Baander at (APP_URL); its origin is always allowed
     * @param list<string> $extraOrigins Further origins the deployment answers on, such as a LAN address
     * @param int $jtiTtlSeconds The proof acceptance window a jti is refused for: the larger
     *     of the nonce TTL and twice the clock skew (wired in config/services.yaml).
     *
     * @throws InvalidArgumentException when an origin is not an absolute http or https URL
     */
    public function __construct(
        private readonly DpopJtiCacheInterface $jtiCache,
        string $publicUrl,
        array $extraOrigins = [],
        private readonly int $clockSkewSeconds = 60,
        private readonly int $jtiTtlSeconds = 300,
        private readonly LoggerInterface $logger = new NullLogger(),
    )
    {
        $this->parser = new Parser(new JoseEncoder());
        $this->publicOrigins = [$publicUrl, ...$extraOrigins]
            |> (static fn (array $urls): array => array_map(trim(...), $urls))
            |> (static fn (array $urls): array => array_filter($urls, static fn (string $url): bool => $url !== ''))
            |> (static fn (array $urls): array => array_map(self::origin(...), $urls))
            |> array_unique(...)
            |> array_values(...);
    }

    /**
     * Check if a DPoP proof JWT contains a nonce claim.
     *
     * Used by token endpoints to implement the challenge-response nonce flow:
     * if no nonce is present, return a `use_dpop_nonce` error with a fresh nonce.
     */
    public function extractNonce(string $dpopJwt): ?string
    {
        try {
            $token = $this->parser->parse($dpopJwt);
        } catch (Throwable) {
            return null;
        }

        if (!($token instanceof Plain)) {
            return null;
        }

        $nonce = $token->claims()->get('nonce');

        return is_string($nonce) ? $nonce : null;
    }

    /**
     * @param bool $skipReplayCheckOnStoreFailure Accept the proof when the jti store cannot
     *     answer, with the result flagged replay-unchecked; every other check still runs. Only
     *     the revocation routes pass it: a replayed logout or revoke can end no session the key
     *     holder could not end with the original. The store's replay verdict is never skipped.
     *
     * @throws DpopStoreUnavailableException when the jti store cannot answer and the caller did not allow skipping the replay check
     */
    public function validate(
        string $dpopJwt,
        Request $request,
        ?string $accessToken = null,
        ?string $expectedJkt = null,
        bool $skipReplayCheckOnStoreFailure = false,
    ): DpopValidationResult
    {
        // 1. Parse the JWT (structural check)
        try {
            $token = $this->parser->parse($dpopJwt);
        } catch (Throwable $e) {
            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof is not a well-formed JWT.');
        }

        if (!($token instanceof Plain)) {
            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof is not a plain (JWS) JWT.');
        }

        // 2. typ = "dpop+jwt" (RFC 9449 §11.5)
        $typ = $token->headers()->get('typ');
        if ($typ !== 'dpop+jwt') {
            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof must have typ "dpop+jwt".');
        }

        // 3. alg is asymmetric, not none, supported (RFC 9449 §11.6)
        $alg = $token->headers()->get('alg');
        if ($alg === null || !is_string($alg)) {
            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof must have an "alg" header.');
        }
        if ($alg === 'none' || !in_array($alg, self::SUPPORTED_ALGORITHMS, true)) {
            return DpopValidationResult::invalid('invalid_dpop_proof', sprintf('Unsupported algorithm "%s".', $alg));
        }

        // 4. jwk header present (public key)
        $jwk = $token->headers()->get('jwk');
        if (!is_array($jwk)) {
            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof must contain a "jwk" header.');
        }

        // 5. No private key in jwk
        foreach (JwkPublicKeyConverter::PRIVATE_KEY_MEMBERS as $member) {
            if (array_key_exists($member, $jwk)) {
                return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof jwk must not contain private key material.');
            }
        }

        // 6. Required claims: jti, htm, htu, iat
        $claims = $token->claims();
        foreach (['jti', 'htm', 'htu', 'iat'] as $required) {
            if ($claims->get($required) === null) {
                return DpopValidationResult::invalid('invalid_dpop_proof', sprintf('DPoP proof missing required claim "%s".', $required));
            }
        }

        $jti = $claims->get('jti');
        if (!is_string($jti)) {
            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof "jti" claim must be a string.');
        }

        // 7. iat within clock skew
        $iat = $claims->get('iat');
        if ($iat instanceof DateTimeImmutable) {
            $now = new DateTimeImmutable();
            $min = (int)$now->getTimestamp() - $this->clockSkewSeconds;
            $max = (int)$now->getTimestamp() + $this->clockSkewSeconds;
            $iatTs = (int)$iat->getTimestamp();

            if ($iatTs < $min || $iatTs > $max) {
                return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof "iat" is outside acceptable clock skew.');
            }
        }

        // 9. htm matches request method
        $htm = $claims->get('htm');
        if (!is_string($htm) || strtolower($htm) !== strtolower($request->getMethod())) {
            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof "htm" does not match request method.');
        }

        // 10. htu matches request URI (no query/fragment, scheme normalization)
        $htu = $claims->get('htu');
        if (!is_string($htu)) {
            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof "htu" must be a string.');
        }

        if (!in_array($htu, $this->acceptedHtus($request), true)) {
            $this->logger->debug('DPoP HTU mismatch', [
                'provided' => $htu,
                'public_origins' => $this->publicOrigins,
                'path_info' => $request->getPathInfo(),
            ]);

            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof "htu" does not match request URI.');
        }

        // 11. Signature verification
        try {
            $verified = JwkPublicKeyConverter::verify($jwk, $alg, $token->payload(), $token->signature()->toString());
        } catch (Throwable $e) {
            $this->logger->debug('DPoP proof verification key is invalid', ['exception' => $e]);

            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof jwk is invalid or unsupported.');
        }

        if (!$verified) {
            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof signature verification failed.');
        }

        // 11. jti replay prevention (RFC 9449 §11.1) — checked after signature verification
        // to prevent poisoning the cache with JTIs from unsigned/forged proofs. The first
        // sight records the jti for the acceptance window. A store outage propagates as
        // DpopStoreUnavailableException unless the caller allowed this one check to be
        // skipped; the jti is then not recorded, and the checks below still run.
        $replayUnchecked = false;
        try {
            if ($this->jtiCache->isReplay($jti, $this->jtiTtlSeconds)) {
                return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof "jti" has been reused.');
            }
        } catch (DpopStoreUnavailableException $outage) {
            if (!$skipReplayCheckOnStoreFailure) {
                throw $outage;
            }
            $replayUnchecked = true;
        }

        // 12. If access token present: ath claim and jkt matching
        if ($accessToken !== null) {
            $ath = $claims->get('ath');
            if (!is_string($ath) || $ath !== $this->hashAccessToken($accessToken)) {
                return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof "ath" does not match access token.');
            }
        }

        if ($expectedJkt !== null) {
            try {
                $proofJkt = DpopJwkThumbprint::compute($jwk);
            } catch (Throwable $e) {
                return DpopValidationResult::invalid('invalid_dpop_proof', 'Failed to compute JWK thumbprint.');
            }

            if (!hash_equals($expectedJkt, $proofJkt)) {
                return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof jwk thumbprint does not match token binding.');
            }
        }

        // Compute jkt from the proof's jwk for the caller
        try {
            $jkt = DpopJwkThumbprint::compute($jwk);
        } catch (Throwable $e) {
            return DpopValidationResult::invalid('invalid_dpop_proof', 'Failed to compute JWK thumbprint.');
        }

        return DpopValidationResult::valid($jkt, replayUnchecked: $replayUnchecked);
    }

    /**
     * Each public origin plus the request path, without query and fragment (RFC 9449 section 4.3).
     *
     * @return list<string>
     */
    private function acceptedHtus(Request $request): array
    {
        $path = $request->getPathInfo();

        return array_map(static fn (string $origin): string => $origin . $path, $this->publicOrigins);
    }

    /** The URL's origin, with the scheme and host in lowercase and the default port left out. */
    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower(is_array($parts) ? $parts['scheme'] ?? '' : '');
        $host = strtolower(is_array($parts) ? $parts['host'] ?? '' : '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidArgumentException(sprintf('The DPoP origin "%s" is not an absolute http or https URL.', $url));
        }
        $port = $parts['port'] ?? null;
        $defaultPort = $scheme === 'https' ? 443 : 80;

        return sprintf('%s://%s%s', $scheme, $host, $port === null || $port === $defaultPort ? '' : ':' . $port);
    }

    private function hashAccessToken(string $accessToken): string
    {
        return $this->base64urlEncode(hash('sha256', $accessToken, binary: true));
    }

    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
