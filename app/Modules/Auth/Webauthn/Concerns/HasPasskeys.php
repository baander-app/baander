<?php

namespace App\Modules\Auth\Webauthn\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;

interface HasPasskeys
{
    public function passkeys(): HasMany;

    public function getPassKeyName(): string;

    public function getPassKeyId(): string;

    public function getPassKeyDisplayName(): string;
}