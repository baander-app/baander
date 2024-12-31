<?php

namespace App\Models;

use App\Modules\Webauthn\WebauthnService;
use Database\Factories\PasskeyFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\{Factory, HasFactory};
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\PublicKeyCredentialSource;

class Passkey extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'name',
        'credential_id',
        'data',
        'last_used_at',
        'counter',
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
                self::decodeBase64($value),
                PublicKeyCredentialSource::class,
            ),
            set: fn(PublicKeyCredentialSource $value)
                => [
                'credential_id' => self::encodeBase64($value->publicKeyCredentialId),
                'data'          => $service->serialize($value),
            ],
        );
    }

    public static function encodeBase64(string $data)
    {
        return Base64UrlSafe::encodeUnpadded($data);
    }

    public static function decodeBase64(string $data)
    {
        return Base64UrlSafe::decodeNoPadding($data);
    }

    protected static function newFactory(): Factory
    {
        return PasskeyFactory::new();
    }
}
