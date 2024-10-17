<?php

namespace App\Auth\Webauthn\Actions;

use App\Auth\Webauthn\Concerns\HasPasskeys;
use App\Auth\Webauthn\Models\PublicKeyCredentialCreationOptionsData;
use App\Auth\Webauthn\WebauthnService;
use Illuminate\Support\Str;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

class GeneratePasskeyRegisterOptionsAction
{
    public function execute(
        HasPasskeys $authenticatable,
        bool        $asJson = true,
    ): string|PublicKeyCredentialCreationOptions
    {

        $authenticatorSelectionCriteria = AuthenticatorSelectionCriteria::create(
            authenticatorAttachment: AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_NO_PREFERENCE,
            residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
        );

        $options = new PublicKeyCredentialCreationOptions(
            rp: $this->relatedPartyEntity(),
            user: $this->generateUserEntity($authenticatable),
            challenge: $this->challenge(),
            authenticatorSelection: $authenticatorSelectionCriteria,
        );

        if ($asJson) {
            $options = app(WebauthnService::class)->serialize($options);
        }

        return $options;
    }

    protected function relatedPartyEntity(): PublicKeyCredentialRpEntity
    {
        return new PublicKeyCredentialRpEntity(
            name: config('webauthn.relying_party.name'),
            id: config('webauthn.relying_party.id'),
            icon: config('webauthn.relying_party.icon'),
        );
    }

    public function generateUserEntity(HasPasskeys $authenticatable): PublicKeyCredentialUserEntity
    {
        return new PublicKeyCredentialUserEntity(
            name: $authenticatable->getPassKeyName(),
            id: $authenticatable->getPassKeyId(),
            displayName: $authenticatable->getPassKeyDisplayName(),
        );
    }

    public function authenticatorSelection()
    {

    }

    protected function challenge(): string
    {
        return Str::random();
    }

    private function getCredential(PublicKeyCredentialUserEntity $userEntity)
    {

    }

    private function getServerPublicKeyCredentialCreationOptionsRequest(string $content)
    {
        return app(WebauthnService::class)->deserialize($content, PublicKeyCredentialCreationOptionsData::class);
    }
}