<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Infrastructure\Security\Passkey\PasskeyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Webauthn\Counter\CounterChecker;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

final class PasskeyServiceTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private CounterChecker&MockObject $counterChecker;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private CacheItemPoolInterface&MockObject $cache;
    private PasskeyService $service;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->counterChecker = $this->createMock(CounterChecker::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->cache = $this->createMock(CacheItemPoolInterface::class);

        $this->service = new PasskeyService(
            appDomain: 'localhost',
            appName: 'Test App',
            timeout: 60000,
            authenticatorAttachment: '',
            userVerification: 'preferred',
            residentKey: 'preferred',
            attestation: 'none',
            counterChecker: $this->counterChecker,
            eventDispatcher: $this->eventDispatcher,
            logger: $this->logger,
            cache: $this->cache,
            supportedAlgorithmIds: [-7, -257],
            jsonEncoder: new JsonEncoder(),
        );
    }

    // --- Challenge storage (Redis-backed) ---

    public function testStoreChallengeWritesToCache(): void
    {
        $creationOptions = PublicKeyCredentialCreationOptions::create(
            rp: \Webauthn\PublicKeyCredentialRpEntity::create(name: 'Test App', id: 'localhost'),
            user: \Webauthn\PublicKeyCredentialUserEntity::create(name: 'user@test.com', id: 'user-1', displayName: 'User'),
            challenge: random_bytes(32),
        );

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('set')->willReturnSelf();
        $cacheItem->method('expiresAfter')->willReturnSelf();

        $this->cache->expects($this->once())
            ->method('getItem')
            ->with($this->stringStartsWith('webauthn_challenge_'))
            ->willReturn($cacheItem);

        $this->cache->expects($this->once())
            ->method('save')
            ->with($cacheItem);

        $key = $this->service->storeChallenge($creationOptions);
        $this->assertNotEmpty($key);
    }

    public function testStoreAndRetrieveChallengeFromCache(): void
    {
        $creationOptions = PublicKeyCredentialCreationOptions::create(
            rp: \Webauthn\PublicKeyCredentialRpEntity::create(name: 'Test App', id: 'localhost'),
            user: \Webauthn\PublicKeyCredentialUserEntity::create(name: 'user@test.com', id: 'user-1', displayName: 'User'),
            challenge: random_bytes(32),
        );

        // Capture the serialized JSON that storeChallenge writes to cache
        $capturedJson = null;
        $storeItem = $this->createMock(CacheItemInterface::class);
        $self = $storeItem;
        $storeItem->method('set')->willReturnCallback(function (string $value) use (&$capturedJson, $self): CacheItemInterface {
            $capturedJson = $value;

            return $self;
        });
        $storeItem->method('expiresAfter')->willReturnSelf();
        $this->cache->method('getItem')->willReturn($storeItem);
        $this->cache->method('save')->willReturn(true);

        $key = $this->service->storeChallenge($creationOptions);
        $this->assertNotEmpty($key);
        $this->assertNotNull($capturedJson);

        // Now replace cache mock for getChallenge to return the captured value
        $retrieveItem = $this->createMock(CacheItemInterface::class);
        $retrieveItem->method('isHit')->willReturn(true);
        $retrieveItem->method('get')->willReturn($capturedJson);

        $newCache = $this->createMock(CacheItemPoolInterface::class);
        $newCache->method('getItem')->willReturn($retrieveItem);
        $newCache->expects($this->once())->method('deleteItem');

        $service = new PasskeyService(
            appDomain: 'localhost',
            appName: 'Test App',
            timeout: 60000,
            authenticatorAttachment: '',
            userVerification: 'preferred',
            residentKey: 'preferred',
            attestation: 'none',
            counterChecker: $this->counterChecker,
            eventDispatcher: $this->eventDispatcher,
            logger: $this->logger,
            cache: $newCache,
            supportedAlgorithmIds: [-7, -257],
            jsonEncoder: new JsonEncoder(),
        );

        $retrieved = $service->getChallenge($key);

        $this->assertInstanceOf(PublicKeyCredentialCreationOptions::class, $retrieved);
    }

    public function testGetChallengeFallsBackToInMemoryWhenCacheFails(): void
    {
        $creationOptions = PublicKeyCredentialCreationOptions::create(
            rp: \Webauthn\PublicKeyCredentialRpEntity::create(name: 'Test App', id: 'localhost'),
            user: \Webauthn\PublicKeyCredentialUserEntity::create(name: 'user@test.com', id: 'user-1', displayName: 'User'),
            challenge: random_bytes(32),
        );

        $key = $this->service->storeChallenge($creationOptions);

        // Cache getItem throws
        $this->cache->expects($this->once())
            ->method('getItem')
            ->willThrowException(new \RuntimeException('Redis connection refused'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Failed to retrieve'));

        // Should fall back to in-memory and return the options
        $retrieved = $this->service->getChallenge($key);
        $this->assertInstanceOf(PublicKeyCredentialCreationOptions::class, $retrieved);
    }

    public function testGetChallengeRemovesFromCache(): void
    {
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: 'localhost',
        );

        $key = $this->service->storeChallenge($options);

        // First retrieval via cache hit should remove from in-memory too
        $hitItem = $this->createMock(CacheItemInterface::class);
        $hitItem->method('isHit')->willReturn(true);
        $hitItem->method('get')->willReturnCallback(function () use ($options) {
            $challenge = rtrim(strtr(base64_encode($options->challenge), '+/', '-_'), '=');
            return json_encode([
                'challenge' => $challenge,
                'rpId' => $options->rpId,
                'userVerification' => $options->userVerification,
                'timeout' => $options->timeout,
                'allowCredentials' => [],
                'extensions' => [],
            ], JSON_THROW_ON_ERROR);
        });

        // Use a fresh service so we can control the cache behavior precisely
        $freshCache = $this->createMock(CacheItemPoolInterface::class);
        $freshCache->expects($this->exactly(2))
            ->method('getItem')
            ->willReturnOnConsecutiveCalls($hitItem, $this->createConfiguredMock(CacheItemInterface::class, ['isHit' => false]));
        $freshCache->expects($this->once())
            ->method('deleteItem');

        $freshService = new PasskeyService(
            appDomain: 'localhost',
            appName: 'Test App',
            timeout: 60000,
            authenticatorAttachment: '',
            userVerification: 'preferred',
            residentKey: 'preferred',
            attestation: 'none',
            counterChecker: $this->counterChecker,
            eventDispatcher: $this->eventDispatcher,
            logger: $this->logger,
            cache: $freshCache,
            supportedAlgorithmIds: [-7, -257],
            jsonEncoder: new JsonEncoder(),
        );

        // First retrieval succeeds (cache hit)
        $freshService->getChallenge($key);

        // Second retrieval should throw because in-memory was cleaned up and cache returns miss
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Challenge not found or has expired');

        $freshService->getChallenge($key);
    }

    public function testGetChallengeThrowsOnUnknownKey(): void
    {
        $missItem = $this->createMock(CacheItemInterface::class);
        $missItem->method('isHit')->willReturn(false);

        $this->cache->expects($this->once())
            ->method('getItem')
            ->willReturn($missItem);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Challenge not found or has expired');

        $this->service->getChallenge('non-existent-key');
    }

    public function testExpiredChallengeReturnsNullFromCacheAndThrows(): void
    {
        // Use a fresh service with empty in-memory store to simulate a restart
        $freshCache = $this->createMock(CacheItemPoolInterface::class);
        $missItem = $this->createMock(CacheItemInterface::class);
        $missItem->method('isHit')->willReturn(false);

        $freshCache->method('getItem')->willReturn($missItem);

        $freshService = new PasskeyService(
            appDomain: 'localhost',
            appName: 'Test App',
            timeout: 60000,
            authenticatorAttachment: '',
            userVerification: 'preferred',
            residentKey: 'preferred',
            attestation: 'none',
            counterChecker: $this->counterChecker,
            eventDispatcher: $this->eventDispatcher,
            logger: $this->logger,
            cache: $freshCache,
            supportedAlgorithmIds: [-7, -257],
            jsonEncoder: new JsonEncoder(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Challenge not found or has expired');

        // Simulate retrieving a challenge whose cache TTL has expired after service restart
        $freshService->getChallenge('some-stale-challenge-key');
    }

    public function testStoreChallengeFallsBackToInMemoryOnCacheFailure(): void
    {
        $creationOptions = PublicKeyCredentialCreationOptions::create(
            rp: \Webauthn\PublicKeyCredentialRpEntity::create(name: 'Test App', id: 'localhost'),
            user: \Webauthn\PublicKeyCredentialUserEntity::create(name: 'user@test.com', id: 'user-1', displayName: 'User'),
            challenge: random_bytes(32),
        );

        $this->cache->method('getItem')
            ->willThrowException(new \RuntimeException('Redis down'));

        $this->logger->expects($this->exactly(2))
            ->method('warning');

        // Still returns a key; stored in-memory
        $key = $this->service->storeChallenge($creationOptions);
        $this->assertNotEmpty($key);

        // Can retrieve via in-memory fallback
        $retrieved = $this->service->getChallenge($key);
        $this->assertSame($creationOptions, $retrieved);
    }

    public function testGetChallengeDeserializesRequestOptions(): void
    {
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: 'localhost',
            userVerification: 'preferred',
            timeout: 60000,
        );

        $key = $this->service->storeChallenge($options);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturnCallback(function () use ($options) {
            $challenge = rtrim(strtr(base64_encode($options->challenge), '+/', '-_'), '=');
            return json_encode([
                'challenge' => $challenge,
                'rpId' => $options->rpId,
                'userVerification' => $options->userVerification,
                'timeout' => $options->timeout,
                'allowCredentials' => [],
                'extensions' => [],
            ], JSON_THROW_ON_ERROR);
        });

        $this->cache->expects($this->once())
            ->method('getItem')
            ->willReturn($cacheItem);

        $this->cache->expects($this->once())
            ->method('deleteItem');

        $retrieved = $this->service->getChallenge($key);
        $this->assertInstanceOf(PublicKeyCredentialRequestOptions::class, $retrieved);
    }

    // --- Registration / Authentication options ---

    public function testCreateRegistrationOptionsReturnsChallengeKeyAndOptions(): void
    {
        $result = $this->service->createRegistrationOptions(
            userId: 'user-1',
            username: 'user@test.com',
            existingCredentialIds: [],
        );

        $this->assertArrayHasKey('challengeKey', $result);
        $this->assertArrayHasKey('options', $result);
        $this->assertNotEmpty($result['challengeKey']);
        $this->assertNotEmpty($result['options']['challenge']);
        $this->assertSame('localhost', $result['options']['rp']['id']);
        $this->assertSame('Test App', $result['options']['rp']['name']);
    }

    public function testCreateRegistrationOptionsExcludesExistingCredentials(): void
    {
        $existingId = base64_encode('existing-cred-id');

        $result = $this->service->createRegistrationOptions(
            userId: 'user-1',
            username: 'user@test.com',
            existingCredentialIds: [$existingId],
        );

        $this->assertCount(1, $result['options']['excludeCredentials']);
    }

    public function testCreateAuthenticationOptionsReturnsChallengeKeyAndOptions(): void
    {
        $credId = base64_encode('cred-id-1');

        $result = $this->service->createAuthenticationOptions([$credId]);

        $this->assertArrayHasKey('challengeKey', $result);
        $this->assertArrayHasKey('options', $result);
        $this->assertNotEmpty($result['options']['challenge']);
        $this->assertSame('localhost', $result['options']['rpId']);
        $this->assertCount(1, $result['options']['allowCredentials']);
    }

    public function testCreateAuthenticationOptionsWithNoCredentials(): void
    {
        $result = $this->service->createAuthenticationOptions();

        $this->assertEmpty($result['options']['allowCredentials']);
    }

    // --- Credential record serialization ---

    public function testCredentialRecordToArrayContainsExpectedKeys(): void
    {
        $record = new CredentialRecord(
            publicKeyCredentialId: 'binary-id',
            type: 'public-key',
            transports: ['internal', 'hybrid'],
            attestationType: 'none',
            trustPath: \Webauthn\TrustPath\EmptyTrustPath::create(),
            aaguid: \Symfony\Component\Uid\Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            credentialPublicKey: 'pub-key',
            userHandle: 'user-handle',
            counter: 42,
        );

        $array = $this->service->credentialRecordToArray($record);

        $this->assertSame(base64_encode('binary-id'), $array['publicKeyCredentialId']);
        $this->assertSame('public-key', $array['type']);
        $this->assertSame(['internal', 'hybrid'], $array['transports']);
        $this->assertSame('none', $array['attestationType']);
        $this->assertSame('00000000-0000-0000-0000-000000000000', $array['aaguid']);
        $this->assertSame(base64_encode('pub-key'), $array['credentialPublicKey']);
        $this->assertSame('user-handle', $array['userHandle']);
    }

    public function testCredentialRecordFromArrayRoundTrip(): void
    {
        $record = new CredentialRecord(
            publicKeyCredentialId: 'binary-id',
            type: 'public-key',
            transports: ['internal'],
            attestationType: 'none',
            trustPath: \Webauthn\TrustPath\EmptyTrustPath::create(),
            aaguid: \Symfony\Component\Uid\Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            credentialPublicKey: 'pub-key',
            userHandle: 'user-handle',
            counter: 10,
        );

        $array = $this->service->credentialRecordToArray($record);
        $restored = $this->service->credentialRecordFromArray($array, 10);

        $this->assertSame($record->publicKeyCredentialId, $restored->publicKeyCredentialId);
        $this->assertSame($record->type, $restored->type);
        $this->assertSame($record->transports, $restored->transports);
        $this->assertSame($record->attestationType, $restored->attestationType);
        $this->assertSame($record->aaguid->toRfc4122(), $restored->aaguid->toRfc4122());
        $this->assertSame($record->credentialPublicKey, $restored->credentialPublicKey);
        $this->assertSame($record->userHandle, $restored->userHandle);
        $this->assertSame(10, $restored->counter);
    }

    public function testCredentialRecordFromArrayUsesProvidedCounter(): void
    {
        $array = [
            'publicKeyCredentialId' => base64_encode('id'),
            'type' => 'public-key',
            'transports' => [],
            'attestationType' => 'none',
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'credentialPublicKey' => base64_encode('key'),
            'userHandle' => 'handle',
        ];

        $restored = $this->service->credentialRecordFromArray($array, 99);

        $this->assertSame(99, $restored->counter);
    }

    // --- Signal creation ---

    public function testCreateAllAcceptedCredentialsSignalReturnsJson(): void
    {
        $credId = base64_encode('cred-id-1');

        $signal = $this->service->createAllAcceptedCredentialsSignal(
            userId: 'user-1',
            username: 'user@test.com',
            credentialIds: [$credId],
        );

        $data = json_decode($signal, true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('rpId', $data);
        $this->assertArrayHasKey('allAcceptedCredentialIds', $data);
        $this->assertSame('localhost', $data['rpId']);
        $this->assertSame('dXNlci0x', $data['userId']);
        $this->assertCount(1, $data['allAcceptedCredentialIds']);
    }

    public function testCreateAllAcceptedCredentialsSignalWithEmptyList(): void
    {
        $signal = $this->service->createAllAcceptedCredentialsSignal(
            userId: 'user-1',
            username: 'user@test.com',
            credentialIds: [],
        );

        $data = json_decode($signal, true, 512, JSON_THROW_ON_ERROR);

        $this->assertEmpty($data['allAcceptedCredentialIds']);
    }

    public function testCreateCurrentUserDetailsSignalReturnsJson(): void
    {
        $signal = $this->service->createCurrentUserDetailsSignal(
            userId: 'user-1',
            username: 'user@test.com',
            displayName: 'Test User',
        );

        $data = json_decode($signal, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('localhost', $data['rpId']);
        $this->assertSame('dXNlci0x', $data['userId']);
        $this->assertSame('user@test.com', $data['name']);
        $this->assertSame('Test User', $data['displayName']);
    }

    public function testCreateUnknownCredentialSignalReturnsJson(): void
    {
        $credId = base64_encode('unknown-cred-id');

        $signal = $this->service->createUnknownCredentialSignal($credId);

        $data = json_decode($signal, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('localhost', $data['rpId']);
        $this->assertArrayHasKey('credentialId', $data);
    }

    public function testCreateUnknownCredentialSignalWithBase64UrlCredentialId(): void
    {
        $binaryId = 'raw-binary-credential';
        $b64UrlId = rtrim(strtr(base64_encode($binaryId), '+/', '-_'), '=');

        $signal = $this->service->createUnknownCredentialSignal($b64UrlId);

        $data = json_decode($signal, true, 512, JSON_THROW_ON_ERROR);

        $this->assertNotEmpty($data['credentialId']);
    }
}
