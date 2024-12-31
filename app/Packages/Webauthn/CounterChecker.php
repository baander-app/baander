<?php

namespace App\Packages\Webauthn;

use App\Packages\Webauthn\Exceptions\CurrentCountExceededSource;
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