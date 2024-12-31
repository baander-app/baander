<?php

namespace App\Auth\Webauthn\Models;

use Spatie\LaravelData\Data;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;

class PublicKeyCredentialCreationOptionsData extends Data
{
    public function __construct(
        /** @var PublicKeyCredentialCreationOptions */
        public ?string $attestation = null,
        /** @var AuthenticatorSelectionCriteria */
        public ?string $userVerification = null,
        /** @var AuthenticatorSelectionCriteria */
        public ?string $residentKey = null,
        /** @var AuthenticatorSelectionCriteria */
        public ?string $authenticatorAttachment = null,
        /** @var array<string, mixed>|null */
        public ?array  $extensions = null,
    )
    {
    }
}