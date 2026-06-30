<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\Passkey;

use App\Auth\Application\Command\Passkey\AuthenticatePasskeyCommand;
use App\Auth\Application\Port\PasskeyVerifierInterface;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
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

        // Extract credential ID from the response (base64url-decode rawId)
        $rawId = $response['rawId'] ?? $response['id'] ?? '';
        $credentialId = self::base64UrlEncode(base64_decode($rawId, true) ?: $rawId);

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

        $passkey->updateCounter($updatedCredential->counter);

        // Mark the passkey as used
        $this->passkeyRepository->markUsed($passkey);

        // If userId was provided (username-less flow with discovery), return it directly.
        if ($command->getUserId() !== null) {
            return $command->getUserId();
        }

        $userId = $this->passkeyRepository->userIdForCredentialId($credentialId);
        if ($userId === null) {
            throw new RuntimeException('Unable to resolve user for the given credential ID.');
        }

        return $userId->toString();
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
