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

    /** credentialId for rawId='raw-cred-id': base64_decode fails, so base64url('raw-cred-id') */
    private const CREDENTIAL_ID = 'cmF3LWNyZWQtaWQ';
    private const RAW_ID = 'raw-cred-id';

    protected function setUp(): void
    {
        $this->passkeyRepository = $this->createMock(PasskeyRepositoryInterface::class);
        $this->passkeyVerifier = $this->createMock(PasskeyVerifierInterface::class);
        $this->handler = new AuthenticatePasskeyHandler($this->passkeyRepository, $this->passkeyVerifier);
    }

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
                'userHandle' => 'user-handle',
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
            userHandle: 'user-handle',
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
            userHandle: 'user-handle',
            counter: $counter,
        );
    }

    /**
     * Sets up mocks for the full verification flow (getChallenge + credentialRecordFromArray + verify).
     * Must be called after setting up ofCredentialId on passkeyRepository.
     */
    private function setUpVerificationMocks(
        Passkey $passkey,
        array $response,
        CredentialRecord $updatedCredential,
    ): void {
        $expectedOptions = PublicKeyCredentialRequestOptions::create(random_bytes(32), 'example.com');
        $storedCredential = $this->createStoredCredential($passkey->getCounter());

        $this->passkeyVerifier->method('getChallenge')
            ->with('challenge-key-123')
            ->willReturn($expectedOptions);

        $this->passkeyVerifier->method('credentialRecordFromArray')
            ->with($passkey->getData(), $passkey->getCounter())
            ->willReturn($storedCredential);

        $this->passkeyVerifier->method('verifyAuthenticationResponse')
            ->with($response, $expectedOptions, $storedCredential)
            ->willReturn($updatedCredential);
    }

    // --- Tests ---

    public function testAuthenticateWithUserIdReturnsProvidedUserId(): void
    {
        $passkey = $this->createPasskey(self::CREDENTIAL_ID, 5);
        $response = $this->createValidResponse();
        $updatedCredential = $this->createUpdatedCredential(10);

        $this->passkeyRepository->method('ofCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn($passkey);
        $this->setUpVerificationMocks($passkey, $response, $updatedCredential);
        $this->passkeyRepository->expects($this->once())->method('markUsed');

        $command = new AuthenticatePasskeyCommand('user-uuid-123', 'challenge-key-123', $response);
        $result = ($this->handler)($command);

        $this->assertSame('user-uuid-123', $result);
        $this->assertSame(10, $passkey->getCounter());
    }

    public function testAuthenticateWithoutUserIdResolvesFromRepo(): void
    {
        $userId = Uuid::v4();
        $passkey = $this->createPasskey(self::CREDENTIAL_ID, 5);
        $response = $this->createValidResponse();
        $updatedCredential = $this->createUpdatedCredential(10);

        $this->passkeyRepository->method('ofCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn($passkey);
        $this->passkeyRepository->method('userIdForCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn($userId);
        $this->setUpVerificationMocks($passkey, $response, $updatedCredential);

        $command = new AuthenticatePasskeyCommand(null, 'challenge-key-123', $response);
        $result = ($this->handler)($command);

        $this->assertSame($userId->toString(), $result);
    }

    public function testThrowsOnCredentialIdNotFound(): void
    {
        $response = $this->createValidResponse();

        $this->passkeyRepository->method('ofCredentialId')
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

        $this->passkeyRepository->method('ofCredentialId')
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

        $this->passkeyRepository->method('ofCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn($passkey);

        $expectedOptions = PublicKeyCredentialRequestOptions::create(random_bytes(32), 'example.com');
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

        $this->passkeyRepository->method('ofCredentialId')
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

        $this->passkeyRepository->method('ofCredentialId')
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

        $this->passkeyRepository->method('ofCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn($passkey);
        $this->passkeyRepository->method('userIdForCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn(null);
        $this->setUpVerificationMocks($passkey, $response, $updatedCredential);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to resolve user');

        ($this->handler)(new AuthenticatePasskeyCommand(null, 'challenge-key-123', $response));
    }

    public function testDoesNotMarkUsedWhenVerificationFails(): void
    {
        $passkey = $this->createPasskey(self::CREDENTIAL_ID, 5);
        $response = $this->createValidResponse();

        $this->passkeyRepository->method('ofCredentialId')
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

        $this->passkeyRepository->method('ofCredentialId')
            ->with(self::CREDENTIAL_ID)
            ->willReturn($passkey);
        $this->setUpVerificationMocks($passkey, $response, $updatedCredential);

        $command = new AuthenticatePasskeyCommand('user-uuid', 'challenge-key-123', $response);
        ($this->handler)($command);

        $this->assertSame(15, $passkey->getCounter());
    }

    public function testHandlesResponseWithIdOnlyNoRawId(): void
    {
        // When rawId is absent, falls back to id
        $response = [
            'id' => 'fallback-id',
            'clientDataJSON' => 'cdj',
            'authenticatorData' => 'ad',
            'signature' => 'sig',
            'userHandle' => '',
        ];

        // base64_decode('fallback-id') returns false, so base64url('fallback-id') is used
        $credentialId = rtrim(strtr(base64_encode('fallback-id'), '+/', '-_'), '=');

        $passkey = $this->createPasskey($credentialId, 5);
        $updatedCredential = $this->createUpdatedCredential(10);

        $this->passkeyRepository->method('ofCredentialId')
            ->with($credentialId)
            ->willReturn($passkey);
        $this->setUpVerificationMocks($passkey, $response, $updatedCredential);

        $command = new AuthenticatePasskeyCommand('user-uuid', 'challenge-key-123', $response);
        $result = ($this->handler)($command);

        $this->assertSame('user-uuid', $result);
    }
}
