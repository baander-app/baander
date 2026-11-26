<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\Passkey;

use App\Auth\Application\Command\Passkey\AuthenticatePasskeyCommand;
use App\Auth\Application\DTO\AuthenticatedPasskeyOwner;
use App\Auth\Application\Port\PasskeyVerifierInterface;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class AuthenticatePasskeyHandler
{
    public function __construct(
        private readonly PasskeyRepositoryInterface $passkeyRepository,
        private readonly PasskeyVerifierInterface $passkeyVerifier,
        private readonly UserRepositoryInterface $users,
    ) {
    }

    /**
     * Verifies a WebAuthn authentication ceremony and returns the authenticated user with the
     * credential epoch issuance must still find.
     *
     * @throws RuntimeException If the challenge is invalid, credential is not found, or verification fails
     */
    #[AsMessageHandler]
    public function __invoke(AuthenticatePasskeyCommand $command): AuthenticatedPasskeyOwner
    {
        $response = $command->getResponse();

        $credentialId = self::credentialId($response);

        // The owner, and with it the credential epoch, is read before the passkey: a reset that
        // removes the passkey and moves the epoch then either hides the passkey from the read
        // below or leaves this login holding an epoch issuance refuses.
        $userId = $this->passkeyRepository->userIdForCredentialId($credentialId);
        if ($userId === null) {
            throw new RuntimeException('No passkey found for the given credential ID.');
        }
        $owner = $this->users->findByUuid($userId);
        if ($owner === null) {
            throw new RuntimeException('Credential owner does not match its registered user handle.');
        }

        $passkey = $this->passkeyRepository->ofCredentialId($credentialId);
        if ($passkey === null) {
            throw new RuntimeException('No passkey found for the given credential ID.');
        }

        // Spend the challenge first: whatever happens below, it cannot be tried again.
        $expectedOptions = $this->passkeyVerifier->consumeLoginChallenge($command->getChallengeKey());

        // Reconstruct the credential source from stored passkey data
        $storedCredential = $this->passkeyVerifier->credentialRecordFromArray(
            $passkey->getData(),
            $passkey->getCounter(),
        );

        // A passkey stands in for both factors only when the authenticator verified the
        // user, so an account with TOTP on refuses an assertion without that flag.
        $updatedCredential = $this->passkeyVerifier->verifyAuthenticationResponse(
            $response,
            $expectedOptions,
            $storedCredential,
            $owner->hasTotp(),
        );

        if (!hash_equals($owner->getPublicId()->toString(), $storedCredential->userHandle)) {
            throw new RuntimeException('Credential owner does not match its registered user handle.');
        }

        // Cloned authenticator detection, as WebAuthn defines it: a counter that does not
        // increase is suspect, unless both counters are 0, which an authenticator without
        // a counter reports on every use.
        $newCounter = $updatedCredential->counter;
        $storedCounter = $passkey->getCounter();
        if (($newCounter !== 0 || $storedCounter !== 0) && $newCounter <= $storedCounter) {
            throw new RuntimeException('Possible cloned authenticator detected: signature counter did not increase.');
        }

        $passkey->updateCounter($newCounter);
        $this->passkeyRepository->markUsed($passkey);

        return new AuthenticatedPasskeyOwner($userId, $owner->getCredentialEpoch());
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
        $base64 = base64_encode($data);

        return rtrim(strtr($base64, '+/', '-_'), '=');
    }
}
