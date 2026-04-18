<?php

namespace App\Modules\Auth\Webauthn\Actions;

use App\Modules\Auth\Webauthn\PasskeyService;
use App\Modules\Auth\Webauthn\WebauthnService;
use Illuminate\Support\Facades\Session;
use App\Primitives\Text;
use Webauthn\PublicKeyCredentialRequestOptions;

class GeneratePasskeyAuthenticationOptionsAction
{
    public function execute(string $email): string
    {
        $service = app(PasskeyService::class);
        $allowedCredentials = $service->getAllowedCredentials($email);

        $options = new PublicKeyCredentialRequestOptions(
            challenge: Text::random(),
            rpId: parse_url(config('app.url'), PHP_URL_HOST),
            allowCredentials: $allowedCredentials,
        );

        $options = app(WebauthnService::class)->serialize($options);

        Session::flash('passkey-authentication-options', $options);

        return $options;
    }
}