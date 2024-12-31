<?php

namespace App\Packages\Webauthn\Actions;

use App\Models\Passkey;
use App\Packages\Webauthn\Concerns\HasPasskeys;
use App\Packages\Webauthn\Exceptions\InvalidPasskey;
use App\Packages\Webauthn\PasskeyService;
use Throwable;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\PublicKeyCredentialSource;

class StorePasskeyAction
{
    public function execute(
        HasPasskeys $authenticatable,
        string      $passkeyJson,
        string      $passkeyOptionsJson,
        string      $hostName,
        array       $additionalProperties = [],
    ): Passkey
    {
        $publicKeyCredentialSource = $this->determinePublicKeyCredentialSource(
            $passkeyJson,
            $passkeyOptionsJson,
            $hostName,
        );

        return $authenticatable->passkeys()->create([
            ...$additionalProperties,
            'data' => $publicKeyCredentialSource,
        ]);
    }

    protected function determinePublicKeyCredentialSource(
        string $passkeyJson,
        string $passkeyOptionsJson,
        string $hostName,
    ): PublicKeyCredentialSource
    {
        $passkeyOptions = $this->getService()->makePublicKeyCredentialCreationOptions($passkeyOptionsJson);
        $publicKeyCredential = $this->getService()->getPublicKeyCredentialByPasskey($passkeyJson);

        if (!$publicKeyCredential->response instanceof AuthenticatorAttestationResponse) {
            throw InvalidPasskey::invalidPublicKeyCredential();
        }

        $csmFactory = new CeremonyStepManagerFactory;
        $creationCsm = $csmFactory->creationCeremony();

        try {
            $publicKeyCredentialSource = AuthenticatorAttestationResponseValidator::create($creationCsm)->check(
                authenticatorAttestationResponse: $publicKeyCredential->response,
                publicKeyCredentialCreationOptions: $passkeyOptions,
                host: $hostName,
            );
        } catch (Throwable $exception) {
            throw InvalidPasskey::invalidAuthenticatorAttestationResponse($exception);
        }

        return $publicKeyCredentialSource;
    }

    /**
     * @return PasskeyService
     */
    private function getService()
    {
        return app(PasskeyService::class);
    }
}