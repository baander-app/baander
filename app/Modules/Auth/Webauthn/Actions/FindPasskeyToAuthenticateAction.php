<?php

namespace App\Modules\Auth\Webauthn\Actions;

use App\Models\Auth\Passkey;
use App\Modules\Auth\Webauthn\WebauthnService;
use Throwable;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialSource;

class FindPasskeyToAuthenticateAction
{
    public function execute(
        string $publicKeyCredentialJson,
        string $passkeyOptionsJson,
    ): ?Passkey
    {
        $publicKeyCredential = $this->determinePublicKeyCredential($publicKeyCredentialJson);

        if (!$publicKeyCredential) {
            return null;
        }

        $passkey = (new \App\Models\Auth\Passkey)->firstWhere('credential_id', $publicKeyCredential->rawId);

        if (!$passkey) {
            return null;
        }

        /** @var PublicKeyCredentialRequestOptions $passkeyOptions */
        $passkeyOptions = app(WebauthnService::class)->deserialize(
            $passkeyOptionsJson,
            PublicKeyCredentialRequestOptions::class,
        );

        $publicKeyCredentialSource = $this->determinePublicKeyCredentialSource(
            $publicKeyCredential,
            $passkeyOptions,
            $passkey,
        );

        if (!$publicKeyCredentialSource) {
            return null;
        }

        $this->updatePasskey($passkey, $publicKeyCredentialSource);

        return $passkey;
    }

    public function determinePublicKeyCredential(
        string $publicKeyCredentialJson,
    ): ?PublicKeyCredential
    {
        $publicKeyCredential = app(WebauthnService::class)->deserialize(
            $publicKeyCredentialJson,
            PublicKeyCredential::class,
        );

        if (!$publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
            return null;
        }

        return $publicKeyCredential;
    }

    protected function determinePublicKeyCredentialSource(
        PublicKeyCredential               $publicKeyCredential,
        PublicKeyCredentialRequestOptions $passkeyOptions,
        Passkey                           $passkey,
    ): ?PublicKeyCredentialSource
    {
        $csmFactory = new CeremonyStepManagerFactory;
        $requestCsm = $csmFactory->requestCeremony();

        try {
            $validator = AuthenticatorAssertionResponseValidator::create($requestCsm);

            $publicKeyCredentialSource = $validator->check(
                publicKeyCredentialSource: $passkey->data,
                authenticatorAssertionResponse: $publicKeyCredential->response,
                publicKeyCredentialRequestOptions: $passkeyOptions,
                host: parse_url(config('app.url'), PHP_URL_HOST),
                userHandle: null,
            );
        } catch (Throwable) {
            return null;
        }

        return $publicKeyCredentialSource;
    }

    protected function updatePasskey(
        Passkey                   $passkey,
        PublicKeyCredentialSource $publicKeyCredentialSource,
    ): self
    {
        $passkey->update([
            'data'         => $publicKeyCredentialSource,
            'last_used_at' => now(),
        ]);

        return $this;
    }
}