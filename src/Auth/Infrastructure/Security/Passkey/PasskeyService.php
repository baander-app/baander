<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\Passkey;

use App\Auth\Application\Port\PasskeyVerifierInterface;
use Cose\Algorithm\Manager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\ECDSA\ES384;
use Cose\Algorithm\Signature\ECDSA\ES512;
use Cose\Algorithm\Signature\EdDSA\Ed25519;
use Cose\Algorithm\Signature\RSA\PS256;
use Cose\Algorithm\Signature\RSA\RS256;
use Cose\Algorithm\Signature\RSA\RS384;
use Cose\Algorithm\Signature\RSA\RS512;
use Cose\Algorithms;
use JsonException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Random\RandomException;
use RuntimeException;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid as SymfonyUuid;
use Psr\Cache\CacheItemPoolInterface;
use Webauthn\AttestationStatement\AndroidKeyAttestationStatementSupport;
use Webauthn\AttestationStatement\AppleAttestationStatementSupport;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\FidoU2FAttestationStatementSupport;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AttestationStatement\PackedAttestationStatementSupport;
use Webauthn\AttestationStatement\TPMAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Counter\CounterChecker;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\Signal\AllAcceptedCredentials;
use Webauthn\Signal\CurrentUserDetails;
use Webauthn\Signal\UnknownCredential;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * WebAuthn ceremony handler using web-auth/webauthn-lib directly.
 *
 * Provides challenge generation, registration verification, and authentication
 * verification for passkey (WebAuthn) flows. Uses CeremonyStepManagerFactory
 * from webauthn-lib v5 for proper cryptographic verification.
 */
final class PasskeyService implements PasskeyVerifierInterface
{
    /** @var array<string, PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions> */
    private array $challenges = [];

    private readonly SerializerInterface $serializer;
    private readonly AuthenticatorAttestationResponseValidator $attestationValidator;
    private readonly AuthenticatorAssertionResponseValidator $assertionValidator;

    /** @var int[] */
    private readonly array $supportedAlgorithmIds;

    /**
     * @param list<int> $supportedAlgorithmIds COSE algorithm identifiers for registration
     */
    public function __construct(
        private readonly string $appDomain,
        private readonly string $appName,
        private readonly int $timeout,
        private readonly string $authenticatorAttachment,
        private readonly string $userVerification,
        private readonly string $residentKey,
        private readonly string $attestation,
        private readonly CounterChecker $counterChecker,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
        private readonly CacheItemPoolInterface $cache,
        array $supportedAlgorithmIds,
        private readonly JsonEncoder $jsonEncoder,
    ) {
        $this->supportedAlgorithmIds = $supportedAlgorithmIds;

        $algorithmManager = self::createAlgorithmManager($supportedAlgorithmIds);

        $attestationStatementSupportManager = new AttestationStatementSupportManager();
        $attestationStatementSupportManager->add(NoneAttestationStatementSupport::create());
        $attestationStatementSupportManager->add(FidoU2FAttestationStatementSupport::create());
        $attestationStatementSupportManager->add(PackedAttestationStatementSupport::create($algorithmManager));
        $attestationStatementSupportManager->add(AndroidKeyAttestationStatementSupport::create());
        $attestationStatementSupportManager->add(AppleAttestationStatementSupport::create());
        $attestationStatementSupportManager->add(TPMAttestationStatementSupport::create());

        $csmFactory = new CeremonyStepManagerFactory();
        $csmFactory->setCounterChecker($this->counterChecker);
        $csmFactory->setAlgorithmManager($algorithmManager);
        $csmFactory->setAttestationStatementSupportManager($attestationStatementSupportManager);

        $this->attestationValidator = AuthenticatorAttestationResponseValidator::create(
            $csmFactory->creationCeremony(),
        );
        $this->attestationValidator->setEventDispatcher($this->eventDispatcher);
        $this->attestationValidator->setLogger($this->logger);

        $this->assertionValidator = AuthenticatorAssertionResponseValidator::create(
            $csmFactory->requestCeremony(),
        );
        $this->assertionValidator->setEventDispatcher($this->eventDispatcher);
        $this->assertionValidator->setLogger($this->logger);

        $this->serializer = new WebauthnSerializerFactory(
            $attestationStatementSupportManager,
        )->create();
    }

    /**
     * @param int[] $algorithmIds COSE algorithm identifiers
     */
    private static function createAlgorithmManager(array $algorithmIds): Manager
    {
        $mapping = [
            Algorithms::COSE_ALGORITHM_ES256 => ES256::create(),
            Algorithms::COSE_ALGORITHM_ES384 => ES384::create(),
            Algorithms::COSE_ALGORITHM_ES512 => ES512::create(),
            Algorithms::COSE_ALGORITHM_RS256 => RS256::create(),
            Algorithms::COSE_ALGORITHM_RS384 => RS384::create(),
            Algorithms::COSE_ALGORITHM_RS512 => RS512::create(),
            Algorithms::COSE_ALGORITHM_PS256 => PS256::create(),
            Algorithms::COSE_ALGORITHM_EDDSA => Ed25519::create(),
        ];

        $manager = Manager::create();
        foreach ($algorithmIds as $id) {
            if (isset($mapping[$id])) {
                $manager->add($mapping[$id]);
            }
        }

        return $manager;
    }

    public function storeChallenge(PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): string
    {
        $key = SymfonyUuid::v4()->toRfc4122();
        $cacheKey = "webauthn_challenge_{$key}";

        try {
            $optionsJson = $this->serializer->serialize(
                $options,
                'json',
                [AbstractObjectNormalizer::SKIP_NULL_VALUES => true],
            );

            $item = $this->cache->getItem($cacheKey);
            $item->set($optionsJson);
            $item->expiresAfter(300);
            $this->cache->save($item);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to store WebAuthn challenge in cache, falling back to in-memory.', [
                'exception' => $e->getMessage(),
            ]);
        }

        $this->challenges[$key] = $options;

        return $key;
    }

    /**
     * @throws RuntimeException If the challenge key is not found or expired
     */
    public function getChallenge(string $key): PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions
    {
        // Try cache first
        $cacheKey = "webauthn_challenge_{$key}";
        $cached = null;

        try {
            $item = $this->cache->getItem($cacheKey);
            if ($item->isHit()) {
                $cached = $item->get();
                $this->cache->deleteItem($cacheKey);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to retrieve WebAuthn challenge from cache.', [
                'exception' => $e->getMessage(),
            ]);
            $cached = null;
        }

        if ($cached !== null) {
            unset($this->challenges[$key]);

            return $this->deserializeChallengeOptions($cached);
        }

        // Fall back to in-memory
        $options = $this->challenges[$key] ?? null;

        if ($options === null) {
            throw new RuntimeException('Challenge not found or has expired.');
        }

        unset($this->challenges[$key]);

        return $options;
    }

    /**
     * Deserialize WebAuthn options from cached JSON.
     *
     * @throws RuntimeException If deserialization fails or type cannot be determined
     */
    private function deserializeChallengeOptions(string $json): PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions
    {
        $data = $this->jsonEncoder->decode($json, 'json');

        // PublicKeyCredentialRequestOptions has rpId, while CreationOptions has rp
        if (isset($data['rpId'])) {
            return $this->serializer->deserialize($json, PublicKeyCredentialRequestOptions::class, 'json');
        }

        return $this->serializer->deserialize($json, PublicKeyCredentialCreationOptions::class, 'json');
    }

    /**
     * Create WebAuthn registration options for navigator.credentials.create().
     *
     * @param string   $userId                User UUID string (used as the user handle)
     * @param string   $username              The user's display name / email
     * @param string[] $existingCredentialIds Base64url-encoded credential IDs to exclude
     *
     * @return array{challengeKey: string, options: array<string, mixed>}
     *
     * @throws RandomException
     * @throws SerializerExceptionInterface
     * @throws JsonException
     */
    public function createRegistrationOptions(string $userId, string $username, array $existingCredentialIds = []): array
    {
        $excludeCredentials = array_map(
            static fn (string $id): PublicKeyCredentialDescriptor => new PublicKeyCredentialDescriptor(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                self::base64UrlDecode($id),
            ),
            $existingCredentialIds,
        );

        $pubKeyCredParams = array_map(
            static fn (int $alg): PublicKeyCredentialParameters => PublicKeyCredentialParameters::create('public-key', $alg),
            $this->supportedAlgorithmIds,
        );

        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create(
                name: $this->appName,
                id: $this->appDomain,
            ),
            PublicKeyCredentialUserEntity::create(
                name: $username,
                id: $userId,
                displayName: $username,
            ),
            random_bytes(32),
            pubKeyCredParams: $pubKeyCredParams,
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                authenticatorAttachment: $this->authenticatorAttachment !== ''
                    ? $this->authenticatorAttachment
                    : null,
                userVerification: $this->userVerification,
                residentKey: $this->residentKey,
            ),
            attestation: $this->attestation,
            excludeCredentials: $excludeCredentials,
            timeout: $this->timeout,
        );

        $challengeKey = $this->storeChallenge($options);

        $optionsJson = $this->serializer->serialize(
            $options,
            'json',
            [AbstractObjectNormalizer::SKIP_NULL_VALUES => true],
        );

        return [
            'challengeKey' => $challengeKey,
            'options' => $this->jsonEncoder->decode($optionsJson, 'json'),
        ];
    }

    /**
     * Verify a WebAuthn registration (attestation) response.
     *
     * @param array<string, mixed>                $response         The JSON from navigator.credentials.create()
     * @param PublicKeyCredentialCreationOptions  $expectedOptions  The stored creation options (including challenge)
     *
     * @throws RuntimeException
     * @throws SerializerExceptionInterface
     * @throws AuthenticatorResponseVerificationException
     */
    public function verifyRegistrationResponse(
        array $response,
        PublicKeyCredentialCreationOptions $expectedOptions,
    ): CredentialRecord {
        $publicKeyCredential = $this->serializer->deserialize(
            $this->jsonEncoder->encode($response, 'json'),
            PublicKeyCredential::class,
            'json',
        );

        $attestationResponse = $publicKeyCredential->response;

        if (!$attestationResponse instanceof AuthenticatorAttestationResponse) {
            throw new RuntimeException('Expected an authenticator attestation response.');
        }

        return $this->attestationValidator->check(
            $attestationResponse,
            $expectedOptions,
            $this->appDomain,
        );
    }

    /**
     * Create WebAuthn authentication options for navigator.credentials.get().
     *
     * @param string[] $allowedCredentialIds Base64url-encoded credential IDs allowed
     *
     * @return array{challengeKey: string, options: array<string, mixed>}
     *
     * @throws RandomException
     * @throws SerializerExceptionInterface
     * @throws JsonException
     */
    public function createAuthenticationOptions(array $allowedCredentialIds = []): array
    {
        $allowCredentials = array_map(
            static fn (string $id): PublicKeyCredentialDescriptor => new PublicKeyCredentialDescriptor(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                self::base64UrlDecode($id),
            ),
            $allowedCredentialIds,
        );

        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            rpId: $this->appDomain,
            allowCredentials: $allowCredentials,
            userVerification: $this->userVerification,
            timeout: $this->timeout,
        );

        $challengeKey = $this->storeChallenge($options);

        $optionsJson = $this->serializer->serialize(
            $options,
            'json',
            [AbstractObjectNormalizer::SKIP_NULL_VALUES => true],
        );

        return [
            'challengeKey' => $challengeKey,
            'options' => $this->jsonEncoder->decode($optionsJson, 'json'),
        ];
    }

    /**
     * Verify a WebAuthn authentication (assertion) response.
     *
     * @param array<string, mixed>               $response          The JSON from navigator.credentials.get()
     * @param PublicKeyCredentialRequestOptions  $expectedOptions   The stored request options
     * @param CredentialRecord                    $storedCredential  The stored credential record
     *
     * @throws RuntimeException
     * @throws SerializerExceptionInterface
     * @throws AuthenticatorResponseVerificationException
     */
    public function verifyAuthenticationResponse(
        array $response,
        PublicKeyCredentialRequestOptions $expectedOptions,
        CredentialRecord $storedCredential,
    ): CredentialRecord {
        $publicKeyCredential = $this->serializer->deserialize(
            $this->jsonEncoder->encode($response, 'json'),
            PublicKeyCredential::class,
            'json',
        );

        $assertionResponse = $publicKeyCredential->response;

        if (!$assertionResponse instanceof AuthenticatorAssertionResponse) {
            throw new RuntimeException('Expected an authenticator assertion response.');
        }

        $userHandle = $assertionResponse->userHandle;

        return $this->assertionValidator->check(
            $storedCredential,
            $assertionResponse,
            $expectedOptions,
            $this->appDomain,
            $userHandle !== '' ? $userHandle : null,
        );
    }

    /**
     * Serialize a CredentialRecord to a storable array for the JSON data column.
     *
     * @return array<string, mixed>
     */
    public function credentialRecordToArray(CredentialRecord $record): array
    {
        return [
            'publicKeyCredentialId' => base64_encode($record->publicKeyCredentialId),
            'type' => $record->type,
            'transports' => $record->transports,
            'attestationType' => $record->attestationType,
            'aaguid' => $record->aaguid->toRfc4122(),
            'credentialPublicKey' => base64_encode($record->credentialPublicKey),
            'userHandle' => $record->userHandle,
        ];
    }

    /**
     * Reconstruct a CredentialRecord from stored array data.
     *
     * @param array<string, mixed> $data    The stored credential data array
     * @param int                  $counter The current sign counter from the passkey
     */
    public function credentialRecordFromArray(array $data, int $counter): CredentialRecord
    {
        return new CredentialRecord(
            publicKeyCredentialId: base64_decode($data['publicKeyCredentialId'], true),
            type: $data['type'],
            transports: $data['transports'],
            attestationType: $data['attestationType'],
            trustPath: EmptyTrustPath::create(),
            aaguid: SymfonyUuid::fromString($data['aaguid']),
            credentialPublicKey: base64_decode($data['credentialPublicKey'], true),
            userHandle: $data['userHandle'],
            counter: $counter,
        );
    }

    /**
     * Create an AllAcceptedCredentials signal.
     *
     * @param string[] $credentialIds Base64url-encoded credential IDs still valid
     *
     * @throws SerializerExceptionInterface
     */
    public function createAllAcceptedCredentialsSignal(string $userId, string $username, array $credentialIds): string
    {
        $credentials = array_map(
            static fn (string $id): PublicKeyCredentialDescriptor => new PublicKeyCredentialDescriptor(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                self::base64UrlDecode($id),
            ),
            $credentialIds,
        );

        $signal = new AllAcceptedCredentials(
            PublicKeyCredentialRpEntity::create(name: $this->appName, id: $this->appDomain),
            PublicKeyCredentialUserEntity::create(name: $username, id: $userId, displayName: $username),
            $credentials,
        );

        return $this->serializeSignal($signal);
    }

    /**
     * @throws SerializerExceptionInterface
     */
    public function createCurrentUserDetailsSignal(string $userId, string $username, string $displayName): string
    {
        $signal = new CurrentUserDetails(
            PublicKeyCredentialRpEntity::create(name: $this->appName, id: $this->appDomain),
            PublicKeyCredentialUserEntity::create(name: $username, id: $userId, displayName: $displayName),
        );

        return $this->serializeSignal($signal);
    }

    /**
     * @throws SerializerExceptionInterface
     */
    public function createUnknownCredentialSignal(string $credentialId): string
    {
        $signal = new UnknownCredential(
            PublicKeyCredentialRpEntity::create(name: $this->appName, id: $this->appDomain),
            new PublicKeyCredentialDescriptor(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                self::base64UrlDecode($credentialId),
            ),
        );

        return $this->serializeSignal($signal);
    }

    /**
     * @throws SerializerExceptionInterface
     */
    private function serializeSignal(object $signal): string
    {
        return $this->serializer->serialize(
            $signal,
            'json',
            [AbstractObjectNormalizer::SKIP_NULL_VALUES => true],
        );
    }

    private static function base64UrlDecode(string $data): string
    {
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=');

        return base64_decode($padded, true);
    }
}
