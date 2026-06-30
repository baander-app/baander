<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\Passkey;

final readonly class AuthenticatePasskeyCommand
{
    /**
     * @param string|null         $userId       User UUID string (null if userHandle-based discovery)
     * @param string              $challengeKey The UUID key returned by PasskeyService.storeChallenge()
     * @param array<string, mixed> $response     The raw WebAuthn response array from the client
     */
    public function __construct(
        private ?string $userId,
        private string $challengeKey,
        private array $response,
    ) {
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function getChallengeKey(): string
    {
        return $this->challengeKey;
    }

    public function getResponse(): array
    {
        return $this->response;
    }
}
