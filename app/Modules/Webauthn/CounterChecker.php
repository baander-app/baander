<?php

namespace App\Modules\Webauthn;

use App\Modules\Webauthn\Exceptions\CurrentCountExceededSource;
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