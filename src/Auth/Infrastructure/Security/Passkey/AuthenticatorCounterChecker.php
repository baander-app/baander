<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\Passkey;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Webauthn\Counter\CounterChecker;
use Webauthn\CredentialRecord;

/**
 * Custom counter checker that detects cloned authenticators.
 *
 * When the assertion counter is not greater than the stored counter, this
 * indicates a cloned device. The check logs the anomaly and throws so the
 * authentication is rejected — the caller can then take further action
 * (e.g. lock the account, revoke sessions).
 */
final class AuthenticatorCounterChecker implements CounterChecker
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function check(CredentialRecord $credentialRecord, int $currentCounter): void
    {
        if ($currentCounter <= $credentialRecord->counter) {
            $this->logger->warning('Cloned authenticator detected: assertion counter not greater than stored counter.', [
                'stored_counter' => $credentialRecord->counter,
                'assertion_counter' => $currentCounter,
                'credential_id' => base64_encode($credentialRecord->publicKeyCredentialId),
                'user_id' => $credentialRecord->userHandle,
            ]);

            throw new RuntimeException('Authenticator counter check failed. This may indicate a cloned device.');
        }
    }
}
