<?php

namespace App\Models;

use App\Auth\Webauthn\WebauthnService;
use Database\Factories\PasskeyFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\{Factory, HasFactory};
use Webauthn\PublicKeyCredentialSource;

class Passkey extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'name',
        'credential_id',
        'data',
        'last_used_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function data(): Attribute
    {
        $service = app(WebauthnService::class);

        return new Attribute(
            get: fn(string $value)
                => $service->deserialize(
                $value,
                PublicKeyCredentialSource::class,
            ),
            set: fn(PublicKeyCredentialSource $value)
                => [
                'credential_id' => $value->publicKeyCredentialId,
                'data'          => $service->serialize($value),
            ],
        );
    }


    protected static function newFactory(): Factory
    {
        return PasskeyFactory::new();
    }
}
