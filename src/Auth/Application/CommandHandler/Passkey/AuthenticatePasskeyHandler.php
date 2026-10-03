<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\Passkey;

use App\Auth\Application\Command\Passkey\AuthenticatePasskeyCommand;
use App\Auth\Application\Port\PasskeyVerifierInterface;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class AuthenticatePasskeyHandler
{
    public function __construct(
        private readonly PasskeyRepositoryInterface $passkeyRepository,
        private readonly PasskeyVerifierInterface $passkeyVerifier,
    ) {
    }

    /**
     * Verifies a WebAuthn authentication ceremony and returns the authenticated user ID.
     *
     * @return string The UUID string of the authenticated user
     *
     * @throws RuntimeException If the challenge is invalid, credential is not found, or verification fails
     */
    #[AsMessageHandler]
    public function __invoke(AuthenticatePasskeyCommand $command): string
    {
        $response = $command->getResponse();

        $credentialId = self::credentialId($response);

        $passkey = $this->passkeyRepository->ofCredentialId($credentialId);
        if ($passkey === null) {
            throw new RuntimeException('No passkey found for the given credential ID.');
        }

        // Retrieve the stored challenge options
        $expectedOptions = $this->passkeyVerifier->getChallenge($command->getChallengeKey());

        // Reconstruct the credential source from stored passkey data
        $storedCredential = $this->passkeyVerifier->credentialRecordFromArray(
            $passkey->getData(),
            $passkey->getCounter(),
        );

        // Verify the authentication response (challenge, origin, signature, user handle)
        $updatedCredential = $this->passkeyVerifier->verifyAuthenticationResponse(
            $response,
            $expectedOptions,
            $storedCredential,
        );

        // Check and update the counter for cloned authenticator detection.
        // A counter less than or equal to the stored value indicates a cloned authenticator.
        if ($updatedCredential->counter <= $passkey->getCounter()) {
            throw new RuntimeException('Possible cloned authenticator detected: signature counter did not increase.');
        }

        $userId = $this->passkeyRepository->userIdForCredentialId($credentialId);
        if ($userId === null) {
            throw new RuntimeException('Unable to resolve user for the given credential ID.');
        }
        // Registration stores the owner's UUID string as the WebAuthn user handle.
        if (!hash_equals($userId->toString(), $storedCredential->userHandle)) {
            throw new RuntimeException('Credential owner does not match its registered user handle.');
        }
        $claimedUserId = $command->getUserId();
        if ($claimedUserId !== null) {
            try {
                $claimed = Uuid::fromString($claimedUserId);
            } catch (\InvalidArgumentException) {
                throw new RuntimeException('Invalid claimed credential owner.');
            }
            if (!$userId->equals($claimed)) {
                throw new RuntimeException('Claimed user does not own the verified credential.');
            }
        }

        // Rejected owner hints never update or persist the credential's counter.
        $passkey->updateCounter($updatedCredential->counter);
        $this->passkeyRepository->markUsed($passkey);

        return $userId->toString();
    }

    /** @param array<string,mixed> $response */
    private static function credentialId(array $response): string
    {
        $rawId = $response['rawId'] ?? $response['id'] ?? null;
        if (!is_string($rawId) || $rawId === '' || strlen($rawId) > 2048
            || preg_match('~\A[A-Za-z0-9+/_-]+={0,2}\z~D', $rawId) !== 1
        ) {
            throw new RuntimeException('Invalid credential identifier.');
        }
        $decoded = base64_decode(strtr($rawId, '-_', '+/'), true);
        if ($decoded === false || $decoded === '') {
            throw new RuntimeException('Invalid credential identifier.');
        }
        $canonical = self::base64UrlEncode($decoded);
        if ($canonical !== rtrim(strtr($rawId, '+/', '-_'), '=')) {
            throw new RuntimeException('Invalid credential identifier.');
        }
        return $canonical;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
