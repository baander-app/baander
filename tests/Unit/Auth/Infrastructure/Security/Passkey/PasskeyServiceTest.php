<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Application\Exception\PasskeyChallengeUnavailableException;
use App\Auth\Application\Port\PasskeyChallengeStoreInterface;
use App\Auth\Infrastructure\Security\Passkey\PasskeyService;
use App\Tests\Fixtures\Auth\InMemoryPasskeyChallengeStore;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Webauthn\Counter\CounterChecker;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialRequestOptions;

final class PasskeyServiceTest extends TestCase
{
    private LoggerInterface&Stub $logger;
    private CounterChecker $counterChecker;
    private EventDispatcherInterface&Stub $eventDispatcher;
    private PasskeyChallengeStoreInterface $challenges;
    private PasskeyService $service;

    protected function setUp(): void
    {
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->counterChecker = $this->createStub(CounterChecker::class);
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->challenges = new InMemoryPasskeyChallengeStore();

        $this->service = $this->createService();
    }

    private function createService(): PasskeyService
    {
        return new PasskeyService(
            appDomain: 'localhost',
            appName: 'Bånder',
            timeout: 60000,
            authenticatorAttachment: '',
            userVerification: 'preferred',
            residentKey: 'preferred',
            attestation: 'none',
            counterChecker: $this->counterChecker,
            eventDispatcher: $this->eventDispatcher,
            logger: $this->logger,
            challenges: $this->challenges,
            supportedAlgorithmIds: [-7, -257],
            jsonEncoder: new JsonEncoder(),
        );
    }

    // --- Challenge storage ---

    private const string USER_A = 'aaaaaaaaaaaaaaaaaaaaa';
    private const string USER_B = 'bbbbbbbbbbbbbbbbbbbbb';

    public function testARegistrationChallengeIssuedForOneUserIsRefusedForAnother(): void
    {
        $issued = $this->service->createRegistrationOptions(self::USER_A, 'a@baander.app');

        try {
            $this->service->consumeRegistrationChallenge(self::USER_B, $issued['challengeKey']);
            self::fail('Another user cannot spend the challenge.');
        } catch (RuntimeException $error) {
            self::assertSame('Challenge not found or has expired.', $error->getMessage());
        }

        $options = $this->service->consumeRegistrationChallenge(self::USER_A, $issued['challengeKey']);
        self::assertSame(self::USER_A, $options->user->id);
    }

    public function testARegistrationChallengeIsSpentOnceFromAnyServiceInstance(): void
    {
        $issued = $this->service->createRegistrationOptions(self::USER_A, 'a@baander.app');
        $otherWorker = $this->createService();

        $otherWorker->consumeRegistrationChallenge(self::USER_A, $issued['challengeKey']);

        $this->expectExceptionObject(new RuntimeException('Challenge not found or has expired.'));
        $this->service->consumeRegistrationChallenge(self::USER_A, $issued['challengeKey']);
    }

    public function testAChallengeServesOnlyTheCeremonyItWasIssuedFor(): void
    {
        $registration = $this->service->createRegistrationOptions(self::USER_A, 'a@baander.app');
        $login = $this->service->createAuthenticationOptions();

        foreach ([
            fn () => $this->service->consumeLoginChallenge($registration['challengeKey']),
            fn () => $this->service->consumeRegistrationChallenge(self::USER_A, $login['challengeKey']),
        ] as $crossed) {
            try {
                $crossed();
                self::fail('A challenge cannot cross ceremonies.');
            } catch (RuntimeException $error) {
                self::assertSame('Challenge not found or has expired.', $error->getMessage());
            }
        }

        self::assertInstanceOf(PublicKeyCredentialRequestOptions::class, $this->service->consumeLoginChallenge($login['challengeKey']));
    }

    public function testAnUnavailableStoreFailsOptionsAndConsumptionAlike(): void
    {
        $this->challenges = $this->createStub(PasskeyChallengeStoreInterface::class);
        $this->challenges->method('put')->willThrowException(new PasskeyChallengeUnavailableException('Passkey challenge storage is unavailable.'));
        $this->challenges->method('take')->willThrowException(new PasskeyChallengeUnavailableException('Passkey challenge storage is unavailable.'));
        $service = $this->createService();

        foreach ([
            fn () => $service->createRegistrationOptions(self::USER_A, 'a@baander.app'),
            fn () => $service->createAuthenticationOptions(),
            fn () => $service->consumeRegistrationChallenge(self::USER_A, 'challenge-key'),
            fn () => $service->consumeLoginChallenge('challenge-key'),
        ] as $operation) {
            try {
                $operation();
                self::fail('No challenge is issued or spent without the store.');
            } catch (PasskeyChallengeUnavailableException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // --- Registration / Authentication options ---

    public function testCreateRegistrationOptionsReturnsChallengeKeyAndOptions(): void
    {
        $result = $this->service->createRegistrationOptions(
            userId: 'sssssssssssssssssssss',
            username: 'user@baander.app',
            existingCredentialIds: [],
        );

        $this->assertArrayHasKey('challengeKey', $result);
        $this->assertArrayHasKey('options', $result);
        $this->assertNotEmpty($result['challengeKey']);
        $this->assertNotEmpty($result['options']['challenge']);
        $this->assertSame('localhost', $result['options']['rp']['id']);
        $this->assertSame('Bånder', $result['options']['rp']['name']);
        $this->assertSame('sssssssssssssssssssss', base64_decode(strtr($result['options']['user']['id'], '-_', '+/'), true));
    }

    public function testRegistrationOptionsAndAccountSignalsRejectUuidHandles(): void
    {
        $uuid = \App\Shared\Domain\Model\Uuid::v7()->toString();
        foreach ([
            fn () => $this->service->createRegistrationOptions($uuid, 'user@baander.app'),
            fn () => $this->service->createAllAcceptedCredentialsSignal($uuid, 'user@baander.app', []),
            fn () => $this->service->createCurrentUserDetailsSignal($uuid, 'user@baander.app', 'User'),
        ] as $operation) {
            try {
                $operation();
                self::fail('WebAuthn user handles must be public IDs.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('PublicId', $error->getMessage());
            }
        }
    }

    public function testCreateRegistrationOptionsExcludesExistingCredentials(): void
    {
        $existingId = base64_encode('existing-cred-id');

        $result = $this->service->createRegistrationOptions(
            userId: 'sssssssssssssssssssss',
            username: 'user@baander.app',
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
            userId: 'sssssssssssssssssssss',
            username: 'user@baander.app',
            credentialIds: [$credId],
        );

        $data = json_decode($signal, true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('rpId', $data);
        $this->assertArrayHasKey('allAcceptedCredentialIds', $data);
        $this->assertSame('localhost', $data['rpId']);
        $this->assertSame(rtrim(strtr(base64_encode('sssssssssssssssssssss'), '+/', '-_'), '='), $data['userId']);
        $this->assertCount(1, $data['allAcceptedCredentialIds']);
    }

    public function testCreateAllAcceptedCredentialsSignalWithEmptyList(): void
    {
        $signal = $this->service->createAllAcceptedCredentialsSignal(
            userId: 'sssssssssssssssssssss',
            username: 'user@baander.app',
            credentialIds: [],
        );

        $data = json_decode($signal, true, 512, JSON_THROW_ON_ERROR);

        $this->assertEmpty($data['allAcceptedCredentialIds']);
    }

    public function testCreateCurrentUserDetailsSignalReturnsJson(): void
    {
        $signal = $this->service->createCurrentUserDetailsSignal(
            userId: 'sssssssssssssssssssss',
            username: 'user@baander.app',
            displayName: 'Test User',
        );

        $data = json_decode($signal, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('localhost', $data['rpId']);
        $this->assertSame(rtrim(strtr(base64_encode('sssssssssssssssssssss'), '+/', '-_'), '='), $data['userId']);
        $this->assertSame('user@baander.app', $data['name']);
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
