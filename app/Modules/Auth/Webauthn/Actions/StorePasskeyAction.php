<?php

namespace App\Modules\Auth\Webauthn\Actions;

use App\Models\Auth\Passkey;
use App\Modules\Auth\Webauthn\Concerns\HasPasskeys;
use App\Modules\Auth\Webauthn\Exceptions\InvalidPasskey;
use App\Modules\Auth\Webauthn\PasskeyService;
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