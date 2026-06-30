<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Port for WebAuthn ceremony verification operations.
 *
 * Implemented by PasskeyService in the infrastructure layer.
 */
interface PasskeyVerifierInterface
{
    /**
     * Retrieve stored challenge options by key.
     *
     * @throws \RuntimeException If the challenge key is not found or expired
     */
    public function getChallenge(string $key): PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions;

    /**
     * Verify a WebAuthn authentication (assertion) response.
     *
     * @param array<string, mixed>               $response          The JSON from navigator.credentials.get()
     * @param PublicKeyCredentialRequestOptions  $expectedOptions   The stored request options
     * @param CredentialRecord                    $storedCredential  The stored credential record
     *
     * @throws \RuntimeException
     * @throws \Webauthn\Exception\AuthenticatorResponseVerificationException
     */
    public function verifyAuthenticationResponse(
        array $response,
        PublicKeyCredentialRequestOptions $expectedOptions,
        CredentialRecord $storedCredential,
    ): CredentialRecord;

    /**
     * Reconstruct a CredentialRecord from stored array data.
     *
     * @param array<string, mixed> $data    The stored credential data array
     * @param int                  $counter The current sign counter from the passkey
     */
    public function credentialRecordFromArray(array $data, int $counter): CredentialRecord;
}
