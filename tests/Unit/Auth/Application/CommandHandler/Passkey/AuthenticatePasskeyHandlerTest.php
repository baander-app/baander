<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\Passkey\AuthenticatePasskeyCommand;
use App\Auth\Application\CommandHandler\Passkey\AuthenticatePasskeyHandler;
use App\Auth\Application\Port\PasskeyVerifierInterface;
use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
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

    /** Canonical browser encoding of the binary credential identifier. */
    private const CREDENTIAL_ID = 'cmF3LWNyZWQtaWQ';
    private const RAW_ID = 'cmF3LWNyZWQtaWQ';
    private const OWNER_ID = '01900000-0000-7000-8000-000000000001';

    protected function setUp(): void
    {
        $this->passkeyRepository = $this->createMock(PasskeyRepositoryInterface::class);
        $this->passkeyVerifier = $this->createMock(PasskeyVerifierInterface::class);
        $this->handler = new AuthenticatePasskeyHandler($this->passkeyRepository, $this->passkeyVerifier);
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
                'userHandle' => self::OWNER_ID,
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
            userHandle: self::OWNER_ID,
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
            userHandle: self::OWNER_ID,
            counter: $counter,
        );
    }

    /**
     * Sets up mocks for the full verification flow (getChallenge + credentialRecordFromArray + verify).
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

        $this->passkeyVerifier->expects($this->once())->method('getChallenge')
            ->with('challenge-key-123')
            ->willReturn($expectedOptions);

        $this->passkeyVerifier->expects($this->once())->method('credentialRecordFromArray')
            ->with($passkey->getData(), $passkey->getCounter())
            ->willReturn($storedCredential);

        $this->passkeyVerifier->expects($this->once())->method('verifyAuthenticationResponse')
            ->with($response, $expectedOptions, $storedCredential)
            ->willReturn($updatedCredential);
        if (!$ownerLookupConfigured) {
            $this->passkeyRepository->method('userIdForCredentialId')->willReturn(Uuid::fromString(self::OWNER_ID));
        }
    }

    // --- Tests ---

    public function testAuthenticateWithUserIdReturnsProvidedUserId(): void
    {
        $passkey = $this->createPasskey(self::CREDENTIAL_ID, 5);
        $response = $this->createValidResponse();
        $updatedCredential = $this->createUpdatedCredential(10);

        $this->passkeyRepository->expects($this->once())->method('ofCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn($passkey);
        $this->setUpVerificationMocks($passkey, $response, $updatedCredential);
        $this->passkeyRepository->expects($this->once())->method('markUsed');

        $command = new AuthenticatePasskeyCommand(self::OWNER_ID, 'challenge-key-123', $response);
        $result = ($this->handler)($command);

        $this->assertSame(self::OWNER_ID, $result);
        $this->assertSame(10, $passkey->getCounter());
    }

    public function testAuthenticateWithoutUserIdResolvesFromRepo(): void
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

        $command = new AuthenticatePasskeyCommand(null, 'challenge-key-123', $response);
        $result = ($this->handler)($command);

        $this->assertSame($userId->toString(), $result);
    }

    public function testThrowsOnCredentialIdNotFound(): void
    {
        $response = $this->createValidResponse();

        $this->passkeyRepository->expects($this->once())->method('ofCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn(null);
        $this->passkeyVerifier->expects($this->never())->method('getChallenge');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No passkey found');

        ($this->handler)(new AuthenticatePasskeyCommand(null, 'challenge-key-123', $response));
    }

    public function testThrowsOnInvalidChallengeKey(): void
    {
        $passkey = $this->createPasskey(self::CREDENTIAL_ID, 5);
        $response = $this->createValidResponse();

        $this->passkeyRepository->expects($this->once())->method('ofCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn($passkey);

        $this->passkeyVerifier->method('getChallenge')
            ->willThrowException(new RuntimeException('Challenge not found or has expired.'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Challenge not found');

        ($this->handler)(new AuthenticatePasskeyCommand(null, 'challenge-key-123', $response));
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

        $this->passkeyVerifier->method('getChallenge')->willReturn($expectedOptions);
        $this->passkeyVerifier->method('credentialRecordFromArray')->willReturn($storedCredential);
        $this->passkeyVerifier->method('verifyAuthenticationResponse')
            ->willThrowException(new RuntimeException('Signature verification failed.'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Signature verification failed');

        ($this->handler)(new AuthenticatePasskeyCommand(null, 'challenge-key-123', $response));
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

        ($this->handler)(new AuthenticatePasskeyCommand(null, 'challenge-key-123', $response));
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

        ($this->handler)(new AuthenticatePasskeyCommand(null, 'challenge-key-123', $response));
    }

    public function testThrowsWhenUserCannotBeResolved(): void
    {
        $passkey = $this->createPasskey(self::CREDENTIAL_ID, 5);
        $response = $this->createValidResponse();
        $updatedCredential = $this->createUpdatedCredential(10);

        $this->passkeyRepository->expects($this->once())->method('ofCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn($passkey);
        $this->passkeyRepository->expects($this->once())->method('userIdForCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn(null);
        $this->setUpVerificationMocks($passkey, $response, $updatedCredential, true);
        $this->passkeyRepository->expects($this->never())->method('markUsed');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to resolve user');

        ($this->handler)(new AuthenticatePasskeyCommand(null, 'challenge-key-123', $response));
    }

    public function testDoesNotMarkUsedWhenVerificationFails(): void
    {
        $passkey = $this->createPasskey(self::CREDENTIAL_ID, 5);
        $response = $this->createValidResponse();

        $this->passkeyRepository->expects($this->once())->method('ofCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn($passkey);
        $this->passkeyVerifier->method('getChallenge')
            ->willThrowException(new RuntimeException('Challenge not found or has expired.'));
        $this->passkeyRepository->expects($this->never())->method('markUsed');

        $this->expectException(RuntimeException::class);

        ($this->handler)(new AuthenticatePasskeyCommand(null, 'challenge-key-123', $response));
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

        $command = new AuthenticatePasskeyCommand(self::OWNER_ID, 'challenge-key-123', $response);
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

        $command = new AuthenticatePasskeyCommand(self::OWNER_ID, 'challenge-key-123', $response);
        $result = ($this->handler)($command);

        $this->assertSame(self::OWNER_ID, $result);
    }
    public function testVictimHintCannotOverrideVerifiedCredentialOwner(): void
    {
        $this->assertRejectedOwnerHint('01900000-0000-7000-8000-000000000002');
    }

    public function testMalformedOwnerHintIsRejectedBeforeCounterPersistence(): void
    {
        $this->assertRejectedOwnerHint('not-a-uuid');
    }

    private function assertRejectedOwnerHint(string $hint): void
    {
        $passkey = $this->createPasskey(self::CREDENTIAL_ID, 5);
        $response = $this->createValidResponse();
        $this->passkeyRepository->method('ofCredentialId')->willReturn($passkey);
        $this->setUpVerificationMocks($passkey, $response, $this->createUpdatedCredential(10));
        $this->passkeyRepository->expects($this->never())->method('markUsed');
        try {
            ($this->handler)(new AuthenticatePasskeyCommand($hint, 'challenge-key-123', $response));
            self::fail('A claimed user must own the verified credential.');
        } catch (RuntimeException) {
            self::assertSame(5, $passkey->getCounter());
        }
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
            ($this->handler)(new AuthenticatePasskeyCommand(null, 'challenge-key-123', $response));
            self::fail('Stored user handle must agree with the persisted owner.');
        } catch (RuntimeException) {
            self::assertSame(5, $passkey->getCounter());
        }
    }

    public function testUrlAndStandardBase64IdentifiersResolveTheSameCredential(): void
    {
        foreach (['-_8', '+/8='] as $rawId) {
            $repository = $this->createMock(PasskeyRepositoryInterface::class);
            $verifier = $this->createMock(PasskeyVerifierInterface::class);
            $repository->expects($this->once())->method('ofCredentialId')->with('-_8')->willReturn(null);
            $verifier->expects($this->never())->method('getChallenge');
            try {
                (new AuthenticatePasskeyHandler($repository, $verifier))(
                    new AuthenticatePasskeyCommand(null, 'challenge', ['rawId' => $rawId]),
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
        $this->passkeyVerifier->expects($this->never())->method('getChallenge');
        foreach ([null, [], 42, '', '***', 'Zh', str_repeat('A', 2049)] as $rawId) {
            try {
                ($this->handler)(new AuthenticatePasskeyCommand(null, 'challenge', ['rawId' => $rawId]));
                self::fail('Invalid credential identifier must fail.');
            } catch (RuntimeException $error) {
                self::assertSame('Invalid credential identifier.', $error->getMessage());
            }
        }
    }

}
