<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\Passkey;

/** A discoverable passkey assertion: the credential it names identifies the account. */
final readonly class AuthenticatePasskeyCommand
{
    /**
     * @param string              $challengeKey The key the sign-in options were stored under
     * @param array<string, mixed> $response     The raw WebAuthn response array from the client
     */
    public function __construct(
        private string $challengeKey,
        private array $response,
    ) {
    }

    public function getChallengeKey(): string
    {
        return $this->challengeKey;
    }

    /** @return array<string, mixed> */
    public function getResponse(): array
    {
        return $this->response;
    }
}
