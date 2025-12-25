<?php

namespace App\Modules\Auth\Webauthn;

use App\Models\Auth\Passkey;
use App\Modules\Auth\Webauthn\Exceptions\InvalidPasskey;
use App\Modules\Auth\Webauthn\Exceptions\InvalidPasskeyOptions;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialUserEntity;

class PasskeyService
{
    /**
     * Retrieves a PublicKeyCredential instance from a JSON string.
     *
     * @param string $json A JSON string representing the public key credential.
     * @return PublicKeyCredential The deserialized public key credential object.
     * @throws InvalidPasskey If the provided JSON is not valid.
     */
    public function getPublicKeyCredentialByPasskey(string $json)
    {
        if (!json_validate($json)) {
            throw InvalidPasskey::invalidJson();
        }

        /** @var PublicKeyCredential $publicKeyCredential */
        $publicKeyCredential = app(WebauthnService::class)->deserialize(
            $json,
            PublicKeyCredential::class,
        );

        return $publicKeyCredential;
    }

    public function getAllowedCredentials(PublicKeyCredentialUserEntity $userEntity)
    {
        $registered = (new \App\Models\Auth\Passkey)->whereId($userEntity->id)->get();

        $allowedCredentials = $registered->map(function (Passkey $passkey) {
            return $passkey->data();
        });

        return $allowedCredentials->toArray();
    }

    /**
     * Generates PublicKeyCredentialCreationOptions from a JSON string.
     *
     * @param string $passkeyOptionsJson A JSON string representing the passkey creation options.
     * @return PublicKeyCredentialCreationOptions The deserialized passkey creation options object.
     * @throws InvalidPasskeyOptions If the provided JSON is not valid.
     */
    public function makePublicKeyCredentialCreationOptions(string $passkeyOptionsJson)
    {
        if (!json_validate($passkeyOptionsJson)) {
            throw InvalidPasskeyOptions::invalidJson();
        }

        /** @var PublicKeyCredentialCreationOptions $passkeyOptions */
        $passkeyOptions = app(WebauthnService::class)->deserialize(
            $passkeyOptionsJson,
            PublicKeyCredentialCreationOptions::class,
        );

        return $passkeyOptions;
    }
}