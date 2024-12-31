<?php

namespace App\Modules\Webauthn\Actions;

use App\Modules\Webauthn\PasskeyService;
use App\Modules\Webauthn\WebauthnService;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Webauthn\PublicKeyCredentialRequestOptions;

class GeneratePasskeyAuthenticationOptionsAction
{
    public function execute(string $email): string
    {
        $service = app(PasskeyService::class);
        $allowedCredentials = $service->getAllowedCredentials($email);

        $options = new PublicKeyCredentialRequestOptions(
            challenge: Str::random(),
            rpId: parse_url(config('app.url'), PHP_URL_HOST),
            allowCredentials: $allowedCredentials,
        );

        $options = app(WebauthnService::class)->serialize($options);

        Session::flash('passkey-authentication-options', $options);

        return $options;
    }
}