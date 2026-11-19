<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Application\Exception\PasskeyChallengeUnavailableException;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Port for WebAuthn ceremony verification operations.
 *
 * Implemented by PasskeyService in the infrastructure layer.
 */
interface PasskeyVerifierInterface
{
    /**
     * The login challenge stored under this key, spent by this call.
     *
     * @throws \RuntimeException If the challenge key is not found, expired or already used
     * @throws PasskeyChallengeUnavailableException
     */
    public function consumeLoginChallenge(string $challengeKey): PublicKeyCredentialRequestOptions;

    /**
     * Verify a WebAuthn authentication (assertion) response.
     *
     * @param array<string, mixed>               $response          The JSON from navigator.credentials.get()
     * @param PublicKeyCredentialRequestOptions  $expectedOptions   The stored request options
     * @param CredentialRecord                    $storedCredential  The stored credential record
     * @param bool $requireUserVerification Refuse an assertion whose authenticator did not verify the user
     *
     * @throws \RuntimeException
     * @throws \Webauthn\Exception\AuthenticatorResponseVerificationException
     */
    public function verifyAuthenticationResponse(
        array $response,
        PublicKeyCredentialRequestOptions $expectedOptions,
        CredentialRecord $storedCredential,
        bool $requireUserVerification,
    ): CredentialRecord;

    /**
     * Reconstruct a CredentialRecord from stored array data.
     *
     * @param array<string, mixed> $data    The stored credential data array
     * @param int                  $counter The current sign counter from the passkey
     */
    public function credentialRecordFromArray(array $data, int $counter): CredentialRecord;
}
