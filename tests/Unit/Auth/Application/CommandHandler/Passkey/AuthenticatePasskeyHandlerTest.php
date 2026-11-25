<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\Passkey\AuthenticatePasskeyCommand;
use App\Auth\Application\CommandHandler\Passkey\AuthenticatePasskeyHandler;
use App\Auth\Application\Port\PasskeyVerifierInterface;
use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\UserState;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\TrustPath\EmptyTrustPath;

#[AllowMockObjectsWithoutExpectations]
final class AuthenticatePasskeyHandlerTest extends TestCase
{
    private PasskeyRepositoryInterface&MockObject $passkeyRepository;
    private PasskeyVerifierInterface&MockObject $passkeyVerifier;
    private AuthenticatePasskeyHandler $handler;
    private UserRepositoryInterface $users;

    /** Canonical browser encoding of the binary credential identifier. */
    private const CREDENTIAL_ID = 'cmF3LWNyZWQtaWQ';
    private const RAW_ID = 'cmF3LWNyZWQtaWQ';
    private const OWNER_ID = '01900000-0000-7000-8000-000000000001';
    private const OWNER_PUBLIC_ID = 'sssssssssssssssssssss';

    protected function setUp(): void
    {
        $this->passkeyRepository = $this->createMock(PasskeyRepositoryInterface::class);
        $this->passkeyVerifier = $this->createMock(PasskeyVerifierInterface::class);
        $this->users = $this->createStub(UserRepositoryInterface::class);
        $this->users->method('findByUuid')->willReturnCallback(static function (Uuid $id): User {
            $now = new \DateTimeImmutable();
            return User::reconstitute(new UserState(
                id: $id, publicId: new PublicId($id->toString() === self::OWNER_ID ? self::OWNER_PUBLIC_ID : str_repeat('v', 21)),
                name: 'Owner', email: 'owner@baander.app', password: 'hash', totpSecretCiphertext: null, createdAt: $now, updatedAt: $now,
            ));
        });
        $this->handler = new AuthenticatePasskeyHandler($this->passkeyRepository, $this->passkeyVerifier, $this->users);
    }

    /** @return array<string,mixed> */
    private function createValidResponse(string $rawId = self::RAW_ID): array
    {
        return [
            'id' => $rawId,
            'rawId' => $rawId,
            'clientDataJSON' => base64_encode(json_encode(['type' => 'webauthn.get', 'challenge' => 'challenge'])),
            'authenticatorData' => 'auth-data',
            'signature' => 'sig',
            'userHandle' => '',
        ];
    }

    private function createPasskey(string $credentialId, int $counter = 5, ?Uuid $uuid = null): Passkey
    {
        return Passkey::create(
            $uuid ?? Uuid::v4(),
            'Test Key',
            $credentialId,
            [
                'publicKeyCredentialId' => base64_encode('binary-cred-id'),
                'type' => 'public-key',
                'transports' => ['internal'],
                'attestationType' => 'none',
                'aaguid' => '00000000-0000-0000-0000-000000000000',
                'credentialPublicKey' => base64_encode('public-key'),
                'userHandle' => self::OWNER_PUBLIC_ID,
            ],
            $counter,
        );
    }

    private function createStoredCredential(int $counter = 5): CredentialRecord
    {
        return new CredentialRecord(
            publicKeyCredentialId: 'binary-cred-id',
            type: 'public-key',
            transports: ['internal'],
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: \Symfony\Component\Uid\Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            credentialPublicKey: 'public-key',
            userHandle: self::OWNER_PUBLIC_ID,
            counter: $counter,
        );
    }

    private function createUpdatedCredential(int $counter = 10): CredentialRecord
    {
        return new CredentialRecord(
            publicKeyCredentialId: 'binary-cred-id',
            type: 'public-key',
            transports: ['internal'],
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: \Symfony\Component\Uid\Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            credentialPublicKey: 'public-key',
            userHandle: self::OWNER_PUBLIC_ID,
            counter: $counter,
        );
    }

    /**
     * Sets up mocks for the full verification flow (consumeLoginChallenge + credentialRecordFromArray + verify).
     * Must be called after setting up ofCredentialId on passkeyRepository.
     * @param array<string,mixed> $response
     */
    private function setUpVerificationMocks(
        Passkey $passkey,
        array $response,
        CredentialRecord $updatedCredential,
        bool $ownerLookupConfigured = false,
    ): void {
        $expectedOptions = PublicKeyCredentialRequestOptions::create(random_bytes(32), 'baander.app');
        $storedCredential = $this->createStoredCredential($passkey->getCounter());

        $this->passkeyVerifier->expects($this->once())->method('consumeLoginChallenge')
            ->with('challenge-key-123')
            ->willReturn($expectedOptions);

        $this->passkeyVerifier->expects($this->once())->method('credentialRecordFromArray')
            ->with($passkey->getData(), $passkey->getCounter())
            ->willReturn($storedCredential);

        $this->passkeyVerifier->expects($this->once())->method('verifyAuthenticationResponse')
            ->with($response, $expectedOptions, $storedCredential, false)
            ->willReturn($updatedCredential);
        if (!$ownerLookupConfigured) {
            $this->passkeyRepository->method('userIdForCredentialId')->willReturn(Uuid::fromString(self::OWNER_ID));
        }
    }

    // --- Tests ---

    public function testTheCredentialResolvesItsOwner(): void
    {
        $userId = Uuid::fromString(self::OWNER_ID);
        $passkey = $this->createPasskey(self::CREDENTIAL_ID, 5);
        $response = $this->createValidResponse();
        $updatedCredential = $this->createUpdatedCredential(10);

        $this->passkeyRepository->expects($this->once())->method('ofCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn($passkey);
        $this->passkeyRepository->expects($this->once())->method('userIdForCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn($userId);
        $this->setUpVerificationMocks($passkey, $response, $updatedCredential, true);

        $command = new AuthenticatePasskeyCommand('challenge-key-123', $response);
        $result = ($this->handler)($command);

        $this->assertSame($userId->toString(), $result->userId->toString());
    }

    public function testThrowsOnCredentialIdNotFound(): void
    {
        $response = $this->createValidResponse();

        $this->passkeyRepository->expects($this->once())->method('userIdForCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn(null);
        $this->passkeyRepository->expects($this->never())->method('ofCredentialId');
        $this->passkeyVerifier->expects($this->never())->method('consumeLoginChallenge');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No passkey found');

        ($this->handler)(new AuthenticatePasskeyCommand('challenge-key-123', $response));
    }

    public function testThrowsOnInvalidChallengeKey(): void
    {
        $passkey = $this->createPasskey(self::CREDENTIAL_ID, 5);
        $response = $this->createValidResponse();

        $this->passkeyRepository->expects($this->once())->method('ofCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn($passkey);

        $this->passkeyRepository->method('userIdForCredentialId')->willReturn(Uuid::fromString(self::OWNER_ID));
        $this->passkeyVerifier->method('consumeLoginChallenge')
            ->willThrowException(new RuntimeException('Challenge not found or has expired.'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Challenge not found');

        ($this->handler)(new AuthenticatePasskeyCommand('challenge-key-123', $response));
    }

    public function testThrowsOnInvalidSignature(): void
    {
        $passkey = $this->createPasskey(self::CREDENTIAL_ID, 5);
        $response = $this->createValidResponse();

        $this->passkeyRepository->expects($this->once())->method('ofCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn($passkey);

        $expectedOptions = PublicKeyCredentialRequestOptions::create(random_bytes(32), 'baander.app');
        $storedCredential = $this->createStoredCredential($passkey->getCounter());

        $this->passkeyVerifier->method('consumeLoginChallenge')->willReturn($expectedOptions);
        $this->passkeyVerifier->method('credentialRecordFromArray')->willReturn($storedCredential);
        $this->passkeyRepository->method('userIdForCredentialId')->willReturn(Uuid::fromString(self::OWNER_ID));
        $this->passkeyVerifier->method('verifyAuthenticationResponse')
            ->willThrowException(new RuntimeException('Signature verification failed.'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Signature verification failed');

        ($this->handler)(new AuthenticatePasskeyCommand('challenge-key-123', $response));
    }

    public function testThrowsOnCounterMismatchClonedAuthenticator(): void
    {
        // Stored counter is 10, but the verification returns counter=5 (less than stored)
        $passkey = $this->createPasskey(self::CREDENTIAL_ID, 10);
        $response = $this->createValidResponse();
        $updatedCredential = $this->createUpdatedCredential(5);

        $this->passkeyRepository->expects($this->once())->method('ofCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn($passkey);
        $this->setUpVerificationMocks($passkey, $response, $updatedCredential);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cloned authenticator');

        ($this->handler)(new AuthenticatePasskeyCommand('challenge-key-123', $response));
    }

    public function testAPasskeyWhoseCounterStaysAtZeroSignsIn(): void
    {
        $passkey = $this->createPasskey(self::CREDENTIAL_ID, 0);
        $response = $this->createValidResponse();

        $this->passkeyRepository->method('ofCredentialId')->willReturn($passkey);
        $this->setUpVerificationMocks($passkey, $response, $this->createUpdatedCredential(0));
        $this->passkeyRepository->expects($this->once())->method('markUsed')->with($passkey);

        self::assertSame(self::OWNER_ID, ($this->handler)(new AuthenticatePasskeyCommand('challenge-key-123', $response))->userId->toString());
        self::assertSame(0, $passkey->getCounter());
    }

    public function testThrowsOnCounterSameAsStoredClonedAuthenticator(): void
    {
        // Counter exactly equal to stored counter indicates cloned authenticator
        $passkey = $this->createPasskey(self::CREDENTIAL_ID, 10);
        $response = $this->createValidResponse();
        $updatedCredential = $this->createUpdatedCredential(10);

        $this->passkeyRepository->expects($this->once())->method('ofCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn($passkey);
        $this->setUpVerificationMocks($passkey, $response, $updatedCredential);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cloned authenticator');

        ($this->handler)(new AuthenticatePasskeyCommand('challenge-key-123', $response));
    }

    public function testThrowsWhenUserCannotBeResolved(): void
    {
        $passkey = $this->createPasskey(self::CREDENTIAL_ID, 5);
        $response = $this->createValidResponse();

        $this->passkeyRepository->method('ofCredentialId')->willReturn($passkey);
        $this->passkeyRepository->expects($this->once())->method('userIdForCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn(null);
        $this->passkeyVerifier->expects($this->never())->method('consumeLoginChallenge');
        $this->passkeyVerifier->expects($this->never())->method('verifyAuthenticationResponse');
        $this->passkeyRepository->expects($this->never())->method('markUsed');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No passkey found');

        ($this->handler)(new AuthenticatePasskeyCommand('challenge-key-123', $response));
    }

    public function testDoesNotMarkUsedWhenVerificationFails(): void
    {
        $passkey = $this->createPasskey(self::CREDENTIAL_ID, 5);
        $response = $this->createValidResponse();

        $this->passkeyRepository->expects($this->once())->method('ofCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn($passkey);
        $this->passkeyRepository->method('userIdForCredentialId')->willReturn(Uuid::fromString(self::OWNER_ID));
        $this->passkeyVerifier->method('consumeLoginChallenge')
            ->willThrowException(new RuntimeException('Challenge not found or has expired.'));
        $this->passkeyRepository->expects($this->never())->method('markUsed');

        $this->expectException(RuntimeException::class);

        ($this->handler)(new AuthenticatePasskeyCommand('challenge-key-123', $response));
    }

    public function testUpdatesCounterOnSuccessfulVerification(): void
    {
        $passkey = $this->createPasskey(self::CREDENTIAL_ID, 5);
        $response = $this->createValidResponse();
        $updatedCredential = $this->createUpdatedCredential(15);

        $this->passkeyRepository->expects($this->once())->method('ofCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn($passkey);
        $this->setUpVerificationMocks($passkey, $response, $updatedCredential);

        $command = new AuthenticatePasskeyCommand('challenge-key-123', $response);
        ($this->handler)($command);

        $this->assertSame(15, $passkey->getCounter());
    }

    public function testHandlesResponseWithIdOnlyNoRawId(): void
    {
        // When rawId is absent, falls back to id
        $response = [
            'id' => 'ZmFsbGJhY2staWQ',
            'clientDataJSON' => 'cdj',
            'authenticatorData' => 'ad',
            'signature' => 'sig',
            'userHandle' => '',
        ];

        // The id field uses the same canonical Base64url bytes as rawId.
        $credentialId = rtrim(strtr(base64_encode('fallback-id'), '+/', '-_'), '=');

        $passkey = $this->createPasskey($credentialId, 5);
        $updatedCredential = $this->createUpdatedCredential(10);

        $this->passkeyRepository->expects($this->once())->method('ofCredentialId')
            ->with($credentialId)
            ->willReturn($passkey);
        $this->setUpVerificationMocks($passkey, $response, $updatedCredential);

        $command = new AuthenticatePasskeyCommand('challenge-key-123', $response);
        $result = ($this->handler)($command);

        $this->assertSame(self::OWNER_ID, $result->userId->toString());
    }

    /**
     * The owner, and with it the credential epoch, is read before the passkey: a reset that
     * removes the passkey and moves the epoch cannot fall between the two reads unnoticed.
     */
    public function testTheOwnersCredentialEpochIsReadBeforeThePasskey(): void
    {
        $reads = [];
        $passkey = $this->createPasskey(self::CREDENTIAL_ID, 5);
        $response = $this->createValidResponse();
        $owner = User::reconstitute(new UserState(
            id: Uuid::fromString(self::OWNER_ID),
            publicId: PublicId::fromString(self::OWNER_PUBLIC_ID),
            name: 'Owner',
            email: 'owner@baander.app',
            password: 'hash',
            totpSecretCiphertext: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            credentialEpoch: 7,
        ));
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByUuid')->willReturnCallback(function () use (&$reads, $owner): User {
            $reads[] = 'owner';

            return $owner;
        });
        $this->passkeyRepository->method('ofCredentialId')->willReturnCallback(function () use (&$reads, $passkey): Passkey {
            $reads[] = 'passkey';

            return $passkey;
        });
        $this->setUpVerificationMocks($passkey, $response, $this->createUpdatedCredential(10));

        $result = (new AuthenticatePasskeyHandler($this->passkeyRepository, $this->passkeyVerifier, $users))(
            new AuthenticatePasskeyCommand('challenge-key-123', $response),
        );

        self::assertSame(['owner', 'passkey'], $reads);
        self::assertSame(7, $result->credentialEpoch);
    }

    public function testStoredUserHandleMustMatchRepositoryOwner(): void
    {
        $passkey = $this->createPasskey(self::CREDENTIAL_ID);
        $response = $this->createValidResponse();
        $this->passkeyRepository->method('ofCredentialId')->willReturn($passkey);
        $this->passkeyRepository->method('userIdForCredentialId')
            ->willReturn(Uuid::fromString('01900000-0000-7000-8000-000000000002'));
        $this->setUpVerificationMocks($passkey, $response, $this->createUpdatedCredential(), true);
        $this->passkeyRepository->expects($this->never())->method('markUsed');
        try {
            ($this->handler)(new AuthenticatePasskeyCommand('challenge-key-123', $response));
            self::fail('Stored user handle must agree with the persisted owner.');
        } catch (RuntimeException) {
            self::assertSame(5, $passkey->getCounter());
        }
    }

    public function testLegacyUuidHandleIsRejectedWithoutCounterPersistence(): void
    {
        $passkey = $this->createPasskey(self::CREDENTIAL_ID);
        $response = $this->createValidResponse();
        $stored = $this->createStoredCredential();
        $stored->userHandle = self::OWNER_ID;
        $this->passkeyRepository->method('ofCredentialId')->willReturn($passkey);
        $this->passkeyRepository->method('userIdForCredentialId')->willReturn(Uuid::fromString(self::OWNER_ID));
        $this->passkeyRepository->expects(self::never())->method('markUsed');
        $this->passkeyVerifier->method('consumeLoginChallenge')->willReturn(PublicKeyCredentialRequestOptions::create(random_bytes(32), 'baander.app'));
        $this->passkeyVerifier->method('credentialRecordFromArray')->willReturn($stored);
        $this->passkeyVerifier->method('verifyAuthenticationResponse')->willReturn($this->createUpdatedCredential());

        try {
            ($this->handler)(new AuthenticatePasskeyCommand('challenge', $response));
            self::fail('An internal UUID handle must require re-enrollment.');
        } catch (RuntimeException $error) {
            self::assertSame('Credential owner does not match its registered user handle.', $error->getMessage());
            self::assertSame(5, $passkey->getCounter());
        }
    }

    public function testUrlAndStandardBase64IdentifiersResolveTheSameCredential(): void
    {
        foreach (['-_8', '+/8='] as $rawId) {
            $repository = $this->createMock(PasskeyRepositoryInterface::class);
            $verifier = $this->createMock(PasskeyVerifierInterface::class);
            $repository->expects($this->once())->method('userIdForCredentialId')->with('-_8')->willReturn(null);
            $verifier->expects($this->never())->method('consumeLoginChallenge');
            try {
                (new AuthenticatePasskeyHandler($repository, $verifier, $this->users))(
                    new AuthenticatePasskeyCommand('challenge', ['rawId' => $rawId]),
                );
                self::fail('Fixture has no credential.');
            } catch (RuntimeException $error) {
                self::assertSame('No passkey found for the given credential ID.', $error->getMessage());
            }
        }
    }

    public function testMalformedCredentialIdentifiersFailBeforeLookup(): void
    {
        $this->passkeyRepository->expects($this->never())->method('ofCredentialId');
        $this->passkeyVerifier->expects($this->never())->method('consumeLoginChallenge');
        foreach ([null, [], 42, '', '***', 'Zh', str_repeat('A', 2049)] as $rawId) {
            try {
                ($this->handler)(new AuthenticatePasskeyCommand('challenge', ['rawId' => $rawId]));
                self::fail('Invalid credential identifier must fail.');
            } catch (RuntimeException $error) {
                self::assertSame('Invalid credential identifier.', $error->getMessage());
            }
        }
    }

}
