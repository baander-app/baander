<?php

namespace App\Modules\Auth\Webauthn;

use App\Modules\Auth\Webauthn\Exceptions\CurrentCountExceededSource;
use Webauthn\PublicKeyCredentialSource;

class CounterChecker implements \Webauthn\Counter\CounterChecker
{

    public function check(PublicKeyCredentialSource $publicKeyCredentialSource, int $currentCounter): void
    {
        if ($currentCounter > $publicKeyCredentialSource->counter) {
            CurrentCountExceededSource::throw($currentCounter, $publicKeyCredentialSource->counter);
        }
    }
}