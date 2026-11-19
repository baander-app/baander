<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Auth\Application\Port\ClientAssertionJtiStoreInterface;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientKeySource;
use App\Auth\Infrastructure\Security\OAuth\ClientAssertionVerifier;
use App\Auth\Infrastructure\Security\OAuth\ClientJwksFetcher;
use App\Auth\Infrastructure\Security\OAuth\ClientKeySetProvider;
use App\Shared\Infrastructure\Http\PublicDestinationPolicy;
use App\Shared\Infrastructure\Http\PublicHttpClient;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Tests\Fixtures\Auth\SignedClientAssertion;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RedisException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The claim rules for `private_key_jwt` client assertions (RFC 7523 as updated by RFC 10070),
 * with a 300-second maximum lifetime and 60 seconds of clock skew.
 */
final class ClientAssertionVerifierTest extends TestCase
{
    private const string ISSUER = 'https://music.baander.app';
    private const int NOW = 1_800_000_000;

    private SignedClientAssertion $key;
    private Client $client;
    private string $clientId;
    /** @var array<string, int> jti records by client and jti, with their TTL */
    private array $jtis = [];

    protected function setUp(): void
    {
        $this->key = new SignedClientAssertion();
        $this->client = Client::registerConfidentialWithKeys('Key client', ['https://baander.app/callback'], ClientKeySource::fromJwks($this->key->jwks()));
        $this->clientId = $this->client->getPublicId()->toString();
    }

    public function testAValidEs256AssertionWithTheIssuerAsAudienceAuthenticates(): void
    {
        self::assertTrue($this->verify($this->assertion()));
    }

    public function testTheIssuerAsAOneElementAudienceArrayAuthenticates(): void
    {
        self::assertTrue($this->verify($this->assertion(['aud' => [self::ISSUER]])));
    }

    /** @return iterable<string, array{mixed}> */
    public static function foreignAudiences(): iterable
    {
        yield 'the token endpoint URL' => [self::ISSUER . '/api/oauth/token'];
        yield 'the issuer together with another value' => [[self::ISSUER, 'https://other.baander.app']];
        yield 'the issuer with a trailing slash' => [self::ISSUER . '/'];
    }

    /** Covers AE9: the issuer is the sole accepted audience, compared without normalization. */
    #[DataProvider('foreignAudiences')]
    public function testAnyOtherAudienceFails(mixed $audience): void
    {
        self::assertFalse($this->verify($this->assertion(['aud' => $audience])));
    }

    /** Covers AE10. */
    public function testTheSameAssertionFailsTheSecondTimeWithinItsLifetime(): void
    {
        $assertion = $this->assertion();

        self::assertTrue($this->verify($assertion));
        self::assertFalse($this->verify($assertion));
    }

    public function testTheJtiIsRecordedPerClientForTheRemainingLifetimePlusSkew(): void
    {
        self::assertTrue($this->verify($this->assertion(['jti' => 'shared-jti', 'exp' => self::NOW + 120])));

        $other = new SignedClientAssertion();
        $otherClient = Client::registerConfidentialWithKeys('Other key client', ['https://baander.app/callback'], ClientKeySource::fromJwks($other->jwks()));
        $otherId = $otherClient->getPublicId()->toString();
        self::assertTrue($this->verifier()->verify($other->create($otherId, self::ISSUER, ['jti' => 'shared-jti'], now: self::NOW), $otherClient), 'Another client may use the same jti.');

        // The 120 seconds left, the skew, and one second for the whole second the window ends in.
        self::assertSame([$this->clientId . '|shared-jti' => 181, $otherId . '|shared-jti' => 181], $this->jtis);
    }

    /**
     * Times are checked in whole seconds, so an assertion stays acceptable to the end of the
     * second its window ends in; a jti recorded part-way through a second must outlive that.
     */
    public function testTheJtiRecordOutlivesTheAcceptanceWindow(): void
    {
        $assertion = $this->assertion(['exp' => self::NOW + 120]);
        $recordedAt = self::NOW + 0.7;
        self::assertTrue($this->verifier(clockAt: $recordedAt)->verify($assertion, $this->client));
        $recordExpiresAt = $recordedAt + array_first($this->jtis);

        $this->jtis = [];
        self::assertFalse($this->verifier(clockAt: $recordExpiresAt)->verify($assertion, $this->client), 'The assertion is still acceptable when its jti record expires.');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function criticalHeaders(): iterable
    {
        yield 'an extension' => [['crit' => ['b64'], 'b64' => true]];
        yield 'an empty list' => [['crit' => []]];
    }

    /**
     * No JWS extension is understood, so any `crit` header refuses the assertion (RFC 7515 section 4.1.11).
     *
     * @param array<string, mixed> $headers
     */
    #[DataProvider('criticalHeaders')]
    public function testAnAssertionWithACritHeaderFails(array $headers): void
    {
        self::assertFalse($this->verify($this->assertion(headers: $headers)));
        self::assertSame([], $this->jtis);
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function types(): iterable
    {
        yield 'no typ (AE11)' => [['typ' => null], true];
        yield 'client-authentication+jwt' => [['typ' => 'client-authentication+jwt'], true];
        yield 'application/client-authentication+jwt' => [['typ' => 'application/client-authentication+jwt'], true];
        yield 'the explicit type in other case' => [['typ' => 'Application/Client-Authentication+JWT'], true];
        yield 'dpop+jwt' => [['typ' => 'dpop+jwt'], false];
        yield 'a bare JWT' => [['typ' => 'JWT'], false];
    }

    /**
     * Covers AE11: the explicit type is accepted, and so is its absence; any other type is not.
     *
     * @param array<string, mixed> $headers
     */
    #[DataProvider('types')]
    public function testTheTypeHeaderIsAbsentOrTheClientAuthenticationType(array $headers, bool $accepted): void
    {
        self::assertSame($accepted, $this->verify($this->assertion(headers: $headers)));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function foreignSubjects(): iterable
    {
        yield 'iss' => [['iss' => 'another-client-id']];
        yield 'sub' => [['sub' => 'another-client-id']];
        yield 'no iss' => [['iss' => null]];
        yield 'no sub' => [['sub' => null]];
    }

    /** @param array<string, mixed> $claims */
    #[DataProvider('foreignSubjects')]
    public function testIssuerAndSubjectMustBeTheClientId(array $claims): void
    {
        self::assertFalse($this->verify($this->assertion($claims)));
    }

    /** @return iterable<string, array{string}> */
    public static function refusedAlgorithms(): iterable
    {
        yield 'HS256' => ['HS256'];
        yield 'none' => ['none'];
    }

    #[DataProvider('refusedAlgorithms')]
    public function testSymmetricAndUnsignedAlgorithmsFail(string $alg): void
    {
        $assertion = $this->assertion(headers: ['alg' => $alg]);
        if ($alg === 'none') {
            $assertion = substr($assertion, 0, strrpos($assertion, '.') + 1);
        }

        self::assertFalse($this->verify($assertion));
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function lifetimes(): iterable
    {
        yield 'exp 300 seconds after iat' => [['iat' => self::NOW, 'exp' => self::NOW + 300], true];
        yield 'exp 301 seconds after iat' => [['iat' => self::NOW, 'exp' => self::NOW + 301], false];
        yield 'expired within the skew' => [['iat' => self::NOW - 100, 'exp' => self::NOW - 30], true];
        yield 'expired beyond the skew' => [['iat' => self::NOW - 120, 'exp' => self::NOW - 61], false];
        yield 'no exp' => [['exp' => null], false];
        yield 'nbf in the future' => [['nbf' => self::NOW + 120], false];
        yield 'nbf within the skew' => [['nbf' => self::NOW + 30], true];
        yield 'iat an hour ahead, exp 200 seconds after it' => [['iat' => self::NOW + 3600, 'exp' => self::NOW + 3800], false];
        yield 'no iat, exp beyond the lifetime plus skew' => [['iat' => null, 'exp' => self::NOW + 361], false];
        yield 'no iat, exp within the lifetime plus skew' => [['iat' => null, 'exp' => self::NOW + 360], true];
    }

    /** @param array<string, mixed> $claims */
    #[DataProvider('lifetimes')]
    public function testTheAssertionMustBeCurrentAndShortLived(array $claims, bool $accepted): void
    {
        self::assertSame($accepted, $this->verify($this->assertion($claims)));
    }

    public function testAnAssertionWithoutAJtiFails(): void
    {
        self::assertFalse($this->verify($this->assertion(['jti' => null])));
    }

    public function testAnAssertionSignedByAnotherKeyFailsAndRecordsNoJti(): void
    {
        $impostor = new SignedClientAssertion();

        self::assertFalse($this->verify($impostor->create($this->clientId, self::ISSUER, now: self::NOW)));
        self::assertSame([], $this->jtis);
    }

    public function testAnUnknownKidFails(): void
    {
        self::assertFalse($this->verify($this->assertion(headers: ['kid' => 'rotated-away'])));
    }

    public function testAnAssertionWithoutKidIsCheckedAgainstTheRegisteredKeys(): void
    {
        self::assertTrue($this->verify($this->assertion(headers: ['kid' => null])));
    }

    public function testAKeyRegisteredForAnotherAlgorithmFails(): void
    {
        $this->client = Client::registerConfidentialWithKeys('Key client', ['https://baander.app/callback'], ClientKeySource::fromJwks(['keys' => [[...$this->key->publicJwk(), 'alg' => 'ES384']]]));
        $this->clientId = $this->client->getPublicId()->toString();

        self::assertFalse($this->verify($this->assertion()));
    }

    public function testADpopProofIsNotAClientAssertion(): void
    {
        $proof = (new SignedDpopProof())->createWithNonce('POST', self::ISSUER . '/api/oauth/token', 'nonce');

        self::assertFalse($this->verify($proof));
        self::assertFalse($this->verify('not-a-jwt'));
    }

    /** The endpoint answers 503 with Retry-After; a replay verdict is never guessed. */
    public function testAJtiStoreOutagePropagates(): void
    {
        $outage = DpopStoreUnavailableException::assertionJtiStore(new RedisException('Connection refused'));
        $store = $this->createStub(ClientAssertionJtiStoreInterface::class);
        $store->method('isReplay')->willThrowException($outage);

        $this->expectExceptionObject($outage);
        $this->verifier($store)->verify($this->assertion(), $this->client);
    }

    private function verify(string $assertion): bool
    {
        return $this->verifier()->verify($assertion, $this->client);
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $headers
     */
    private function assertion(array $claims = [], array $headers = []): string
    {
        return $this->key->create($this->clientId, self::ISSUER, $claims, $headers, self::NOW);
    }

    private function verifier(?ClientAssertionJtiStoreInterface $store = null, int|float $clockAt = self::NOW): ClientAssertionVerifier
    {
        return new ClientAssertionVerifier(
            self::inlineKeyProvider(),
            $store ?? $this->recordingStore(),
            new MockClock(\DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $clockAt)) ?: throw new \LogicException('Bad clock time.')),
            self::ISSUER,
            maxLifetimeSeconds: 300,
            clockSkewSeconds: 60,
        );
    }

    /** Serves inline key sets; a fetch or a Redis call would fail the test. */
    private function inlineKeyProvider(): ClientKeySetProvider
    {
        return new ClientKeySetProvider(
            new ClientJwksFetcher(new PublicHttpClient(new MockHttpClient([]), new PublicDestinationPolicy(static fn (): array => []))),
            new ArrayAdapter(),
            $this->createStub(RedisClientFactory::class),
        );
    }

    private function recordingStore(): ClientAssertionJtiStoreInterface
    {
        $jtis = &$this->jtis;

        return new class ($jtis) implements ClientAssertionJtiStoreInterface {
            /** @param array<string, int> $jtis */
            public function __construct(private array &$jtis)
            {
            }

            public function isReplay(string $clientId, string $jti, int $ttlSeconds): bool
            {
                $key = $clientId . '|' . $jti;
                if (isset($this->jtis[$key])) {
                    return true;
                }
                $this->jtis[$key] = $ttlSeconds;

                return false;
            }
        };
    }
}
