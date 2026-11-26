<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Application\Exception\PasskeyChallengeUnavailableException;
use App\Auth\Application\Port\PasskeyChallengeStoreInterface;
use App\Auth\Infrastructure\Security\Passkey\PasskeyService;
use App\Shared\Application\Http\PublicOrigins;
use App\Tests\Fixtures\Auth\InMemoryPasskeyChallengeStore;
use App\Tests\Fixtures\Auth\WebAuthnTestCredential;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Uid\Uuid;
use Webauthn\Counter\CounterChecker;
use Webauthn\CredentialRecord;
use Webauthn\Exception\AttestationStatementLoadingException;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\Exception\InvalidDataException;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\TrustPath\EmptyTrustPath;

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

    private function createService(PublicOrigins $publicOrigins = new PublicOrigins('https://localhost'), string $attestation = 'none'): PasskeyService
    {
        return new PasskeyService(
            publicOrigins: $publicOrigins,
            appName: 'Bånder',
            timeout: 60000,
            authenticatorAttachment: '',
            userVerification: 'preferred',
            residentKey: 'preferred',
            attestation: $attestation,
            counterChecker: $this->counterChecker,
            eventDispatcher: $this->eventDispatcher,
            logger: $this->logger,
            challenges: $this->challenges,
            supportedAlgorithmIds: [-7, -257],
            jsonEncoder: new JsonEncoder(),
        );
    }

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
        $userIdBase64 = strtr($result['options']['user']['id'], '-_', '+/');
        $this->assertSame('sssssssssssssssssssss', base64_decode($userIdBase64, true));
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

    public function testAuthenticationOptionsListNoCredential(): void
    {
        $result = $this->service->createAuthenticationOptions();

        $this->assertArrayHasKey('challengeKey', $result);
        $this->assertNotEmpty($result['options']['challenge']);
        $this->assertSame('localhost', $result['options']['rpId']);
        $this->assertSame([], $result['options']['allowCredentials']);
    }

    public function testTheRelyingPartyIsTheHostOfThePublicUrl(): void
    {
        $service = $this->createService(new PublicOrigins('https://Baander.App:8443/app', ['https://lan.baander.app', 'http://127.0.0.1:9501']));

        $registration = $service->createRegistrationOptions(self::USER_A, 'a@baander.app');
        $authentication = $service->createAuthenticationOptions();

        $this->assertSame('baander.app', $registration['options']['rp']['id']);
        $this->assertSame('baander.app', $authentication['options']['rpId']);
    }

    /**
     * The public URL is https://baander.app, and https://lan.baander.app:8443 and
     * http://127.0.0.1:9501 are listed as extra origins.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function registrationOrigins(): iterable
    {
        yield 'the public origin' => ['https://baander.app', true];
        yield 'a listed origin under the relying party' => ['https://lan.baander.app:8443', true];
        yield 'an unlisted subdomain' => ['https://evil.baander.app', false];
        yield 'an unlisted port' => ['https://baander.app:8443', false];
        yield 'a listed origin off the relying party' => ['http://127.0.0.1:9501', false];
    }

    #[DataProvider('registrationOrigins')]
    public function testOnlyTheConfiguredOriginsOnTheRelyingPartyMayRegister(string $origin, bool $accepted): void
    {
        $service = $this->createService(new PublicOrigins('https://baander.app', ['https://lan.baander.app:8443', 'http://127.0.0.1:9501']));
        $issued = $service->createRegistrationOptions(self::USER_A, 'a@baander.app');
        $attestation = new WebAuthnTestCredential(self::USER_A, 'baander.app')->attest($issued['options']['challenge'], $origin);

        try {
            $service->verifyRegistrationResponse($attestation, $service->consumeRegistrationChallenge(self::USER_A, $issued['challengeKey']));
        } catch (AuthenticatorResponseVerificationException $error) {
            self::assertFalse($accepted, $error->getMessage());
            self::assertStringStartsWith('Invalid origin.', $error->getMessage());

            return;
        }
        self::assertTrue($accepted, 'A registration from ' . $origin . ' must be refused.');
    }

    public function testARegistrationWithoutAnAttestationStatementIsVerified(): void
    {
        $issued = $this->service->createRegistrationOptions(self::USER_A, 'a@baander.app');
        $attestation = new WebAuthnTestCredential(self::USER_A, 'localhost')->attest($issued['options']['challenge'], 'https://localhost');

        $record = $this->service->verifyRegistrationResponse($attestation, $this->service->consumeRegistrationChallenge(self::USER_A, $issued['challengeKey']));

        self::assertSame('none', $record->attestationType);
        self::assertSame(self::USER_A, $record->userHandle);
    }

    /**
     * The statement formats registration options never ask an authenticator for.
     *
     * @return iterable<string, array{string}>
     */
    public static function unrequestedAttestationFormats(): iterable
    {
        yield 'FIDO U2F' => ['fido-u2f'];
        yield 'Android Key' => ['android-key'];
        yield 'Apple' => ['apple'];
        yield 'TPM' => ['tpm'];
    }

    #[DataProvider('unrequestedAttestationFormats')]
    public function testARegistrationInAnUnrequestedAttestationFormatIsRefusedBeforeItsStatementIsRead(string $format): void
    {
        $issued = $this->service->createRegistrationOptions(self::USER_A, 'a@baander.app');
        $attestation = new WebAuthnTestCredential(self::USER_A, 'localhost')->attest($issued['options']['challenge'], 'https://localhost', $format);
        $expectedOptions = $this->service->consumeRegistrationChallenge(self::USER_A, $issued['challengeKey']);

        $this->expectException(InvalidDataException::class);
        $this->expectExceptionMessage('The attestation statement format "' . $format . '" is not supported.');
        $this->service->verifyRegistrationResponse($attestation, $expectedOptions);
    }

    #[DataProvider('unrequestedAttestationFormats')]
    public function testAServiceThatRequestsAttestationReadsThatStatementFormat(string $format): void
    {
        $service = $this->createService(attestation: 'direct');
        $issued = $service->createRegistrationOptions(self::USER_A, 'a@baander.app');
        $attestation = new WebAuthnTestCredential(self::USER_A, 'localhost')->attest($issued['options']['challenge'], 'https://localhost', $format);
        $expectedOptions = $service->consumeRegistrationChallenge(self::USER_A, $issued['challengeKey']);

        // The fixture's statement is empty, so the format's own parser rejects it: the name was accepted.
        $this->expectException(AttestationStatementLoadingException::class);
        $service->verifyRegistrationResponse($attestation, $expectedOptions);
    }

    // --- Credential record serialization ---

    public function testCredentialRecordToArrayContainsExpectedKeys(): void
    {
        $record = new CredentialRecord(
            publicKeyCredentialId: 'binary-id',
            type: 'public-key',
            transports: ['internal', 'hybrid'],
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::fromString('00000000-0000-0000-0000-000000000000'),
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
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::fromString('00000000-0000-0000-0000-000000000000'),
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
        $userIdBase64 = base64_encode('sssssssssssssssssssss');
        $expectedUserId = rtrim(strtr($userIdBase64, '+/', '-_'), '=');
        $this->assertSame($expectedUserId, $data['userId']);
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
        $userIdBase64 = base64_encode('sssssssssssssssssssss');
        $expectedUserId = rtrim(strtr($userIdBase64, '+/', '-_'), '=');
        $this->assertSame($expectedUserId, $data['userId']);
        $this->assertSame('user@baander.app', $data['name']);
        $this->assertSame('Test User', $data['displayName']);
    }
}
