<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Port\ClientAssertionJtiStoreInterface;
use App\Auth\Application\Port\ClientAssertionVerifierInterface;
use App\Auth\Application\Port\ClientKeySetProviderInterface;
use App\Auth\Domain\Model\OAuth\Client;
use DateTimeImmutable;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Verifies `private_key_jwt` client assertions (RFC 7523 as updated by RFC 10070).
 *
 * - `alg` is one of the asymmetric algorithms DPoP proofs use; `none` and HMAC never are.
 * - No `crit` header: no JWS extension is understood (RFC 7515 section 4.1.11).
 * - `typ` is absent or `client-authentication+jwt`, with or without the `application/`
 *   prefix and in any case; any other type, such as a DPoP proof's, is refused.
 * - `iss` and `sub` are the client's ID. `aud` is exactly the issuer identifier, sent as a
 *   string or as a one-element array; the token or PAR endpoint URL is refused, and nothing
 *   is normalized, so a trailing slash differs.
 * - `exp` is required and lies at most the maximum lifetime (plus clock skew) ahead; when
 *   `iat` is present it may not lie in the future beyond the skew, and `exp - iat` may not
 *   exceed the maximum lifetime either. `nbf` is honored.
 * - The signature verifies with one of the client's registered keys, selected by `kid`.
 * - `jti` is required and accepted once per client, recorded only for an assertion that
 *   passed every other check, for its remaining lifetime plus the skew and one second:
 *   times are checked in whole seconds, so the assertion stays acceptable to the end of
 *   the second its window ends in.
 *
 * Which check failed is logged, never answered.
 */
final readonly class ClientAssertionVerifier implements ClientAssertionVerifierInterface
{
    /** The accepted signing algorithms; the metadata test pins the advertised copy to this list. */
    public const array SUPPORTED_ALGORITHMS = JwkPublicKeyConverter::SIGNING_ALGORITHMS;

    private const string EXPLICIT_TYPE = 'client-authentication+jwt';

    private Parser $parser;

    public function __construct(
        private ClientKeySetProviderInterface $keySetProvider,
        private ClientAssertionJtiStoreInterface $jtiStore,
        private ClockInterface $clock,
        private string $issuer,
        private int $maxLifetimeSeconds = 300,
        private int $clockSkewSeconds = 60,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $this->parser = new Parser(new JoseEncoder());
    }

    public function verify(string $assertion, Client $client): bool
    {
        try {
            $token = $this->parser->parse($assertion);
        } catch (Throwable) {
            return $this->reject('it is not a well-formed JWT');
        }
        if (!$token instanceof Plain) {
            return $this->reject('it is not a signed JWT');
        }

        $headers = $token->headers();
        $alg = $headers->get('alg');
        if (!is_string($alg) || !in_array($alg, self::SUPPORTED_ALGORITHMS, true)) {
            return $this->reject('its algorithm is not accepted');
        }
        if ($headers->has('crit')) {
            return $this->reject('it carries a crit header');
        }
        if (!self::isAcceptedType(self::sentType($assertion))) {
            return $this->reject('its typ is not client-authentication+jwt');
        }
        $kid = $headers->get('kid');
        if ($kid !== null && !is_string($kid)) {
            return $this->reject('its kid is not a string');
        }

        $clientId = $client->getPublicId()->toString();
        $claims = $token->claims();
        $now = $this->clock->now()->getTimestamp();
        $failure = $this->subjectFailure($claims, $clientId) ?? $this->timeFailure($claims, $now);
        if ($failure !== null) {
            return $this->reject($failure);
        }

        $jti = $claims->get('jti');
        if (!is_string($jti) || $jti === '') {
            return $this->reject('it carries no jti');
        }

        if (!$this->isSignedByTheClient($token, $client, $alg, $kid)) {
            return $this->reject('no registered key of the client verifies its signature');
        }

        // The time checks above guarantee an exp.
        $remainingSeconds = (self::timestamp($claims, 'exp') ?? $now) - $now;
        if ($this->jtiStore->isReplay($clientId, $jti, max(1, $remainingSeconds + $this->clockSkewSeconds) + 1)) {
            return $this->reject('its jti was used before');
        }

        return true;
    }

    /**
     * The `typ` header as sent. The parser reports a missing `typ` as "JWT", which this
     * verifier refuses, so the header is read from the encoded token instead.
     */
    private static function sentType(string $assertion): mixed
    {
        $header = json_decode(JwkPublicKeyConverter::base64urlDecode(strstr($assertion, '.', true) ?: ''), true);

        return is_array($header) ? $header['typ'] ?? null : false;
    }

    private static function isAcceptedType(mixed $typ): bool
    {
        if ($typ === null) {
            return true;
        }
        if (!is_string($typ)) {
            return false;
        }

        $type = strtolower($typ);

        return $type === self::EXPLICIT_TYPE || $type === 'application/' . self::EXPLICIT_TYPE;
    }

    private function subjectFailure(DataSet $claims, string $clientId): ?string
    {
        return match (true) {
            $claims->get('iss') !== $clientId => 'its iss is not the client ID',
            $claims->get('sub') !== $clientId => 'its sub is not the client ID',
            // The parser turns a string audience into a one-element list.
            $claims->get('aud') !== [$this->issuer] => 'its aud is not the issuer identifier alone',
            default => null,
        };
    }

    private function timeFailure(DataSet $claims, int $now): ?string
    {
        $exp = self::timestamp($claims, 'exp');
        $iat = self::timestamp($claims, 'iat');
        $nbf = self::timestamp($claims, 'nbf');

        return match (true) {
            $exp === null => 'it carries no exp',
            $exp < $now - $this->clockSkewSeconds => 'it has expired',
            $exp - $now > $this->maxLifetimeSeconds + $this->clockSkewSeconds => 'its exp lies beyond the maximum lifetime',
            $iat !== null && $iat > $now + $this->clockSkewSeconds => 'its iat lies in the future',
            $iat !== null && $exp - $iat > $this->maxLifetimeSeconds => 'its lifetime exceeds the maximum',
            $nbf !== null && $nbf > $now + $this->clockSkewSeconds => 'it is not valid yet',
            default => null,
        };
    }

    private function isSignedByTheClient(Plain $token, Client $client, string $alg, ?string $kid): bool
    {
        foreach ($this->keySetProvider->signingKeys($client, $kid) as $jwk) {
            if (isset($jwk['alg']) && $jwk['alg'] !== $alg) {
                continue;
            }

            try {
                if (JwkPublicKeyConverter::verify($jwk, $alg, $token->payload(), $token->signature()->toString())) {
                    return true;
                }
            } catch (Throwable $e) {
                $this->logger->debug('A registered client key cannot verify an assertion', ['exception' => $e]);
            }
        }

        return false;
    }

    private static function timestamp(DataSet $claims, string $name): ?int
    {
        $value = $claims->get($name);

        return $value instanceof DateTimeImmutable ? $value->getTimestamp() : null;
    }

    private function reject(string $reason): bool
    {
        $this->logger->info('Client assertion refused: {reason}', ['reason' => $reason]);

        return false;
    }
}
