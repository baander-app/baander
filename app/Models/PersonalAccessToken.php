<?php

namespace App\Models;

use App\Modules\DeviceDetector\Device;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use JetBrains\PhpStorm\ArrayShape;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use Laravel\Sanctum\NewAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'user_agent',
        'client_name',
        'client_version',
        'client_type',
        'device_operating_system',
        'device_name',
    ];

    /**
     * The "booted" method of the model.
     *
     * @return void
     */
    protected static function booted()
    {
        // Cache token when created
        static::created(function ($token) {
            self::cacheToken($token);
        });

        // Update cache when token is updated
        static::updated(function ($token) {
            self::cacheToken($token);
        });

        // Remove from cache when token is deleted
        static::deleted(function ($token) {
            self::invalidateTokenCache($token->id);
        });
    }

    /**
     * Create a new personal access token for the user.
     *
     * @param  mixed  $tokenable
     * @param  string  $name
     * @param  array  $abilities
     * @param  \DateTimeInterface|null  $expiresAt
     * @return \Laravel\Sanctum\NewAccessToken
     */
    public static function createToken($tokenable, string $name, array $abilities = ['*'], \DateTimeInterface $expiresAt = null)
    {
        $plainTextToken = Str::random(40);

        $token = $tokenable->tokens()->create([
            'name' => $name,
            'token' => hash('sha256', $plainTextToken),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);

        // Token will be cached via the created event

        return new NewAccessToken($token, $tokenable->getKey().'|'.$plainTextToken);
    }

    /**
     * Find the token instance matching the given token.
     *
     * @param string $token
     * @return static|null
     */
    public static function findToken($token)
    {
        if (!config('sanctum.token_cache.enabled', true)) {
            return self::findTokenWithoutCache($token);
        }

        try {
            if (!str_contains($token, '|')) {
                $token = Crypt::decryptString($token);
            }

            [$id, $tokenValue] = explode('|', $token, 2);

            return self::findTokenFromCache($id, $tokenValue);
        } catch (\Exception $e) {
            report($e);
            return null;
        }
    }

    /**
     * Find token from Redis cache or database with caching.
     *
     * @param string $id
     * @param string $tokenValue
     * @return static|null
     */
    protected static function findTokenFromCache($id, $tokenValue)
    {
        $cacheConfig = config('sanctum.token_cache');
        $cacheKey = $cacheConfig['prefix'] . $id;
        $cacheStore = $cacheConfig['store'];

        // Get the TTL value as integer or null
        $ttl = is_numeric($cacheConfig['ttl']) ? (int) $cacheConfig['ttl'] : null;

        return Cache::store($cacheStore)->remember(
            $cacheKey,
            $ttl ? now()->addMinutes($ttl) : null,
            function () use ($id, $tokenValue) {
                if ($instance = static::find($id)) {
                    return hash_equals($instance->token, hash('sha256', $tokenValue)) ? $instance : null;
                }

                return null;
            }
        );
    }

    /**
     * Find token without using cache (fallback method).
     *
     * @param string $token
     * @return static|null
     */
    protected static function findTokenWithoutCache($token)
    {
        if (!str_contains($token, '|')) {
            $token = Crypt::decryptString($token);
        }

        [$id, $tokenValue] = explode('|', $token, 2);

        if ($instance = static::find($id)) {
            return hash_equals($instance->token, hash('sha256', $tokenValue)) ? $instance : null;
        }

        return null;
    }

    /**
     * Cache token instance.
     *
     * @param PersonalAccessToken $token
     * @return void
     */
    public static function cacheToken($token)
    {
        if (!config('sanctum.token_cache.enabled', true)) {
            return;
        }

        $cacheConfig = config('sanctum.token_cache');
        $cacheKey = $cacheConfig['prefix'] . $token->id;

        // Ensure TTL is a proper integer or null
        $ttl = is_numeric($cacheConfig['ttl']) ? (int) $cacheConfig['ttl'] : null;

        Cache::store($cacheConfig['store'])->put(
            $cacheKey,
            $token,
            $ttl ? now()->addMinutes($ttl) : null
        );
    }

    /**
     * Invalidate token cache by ID.
     *
     * @param int $tokenId
     * @return void
     */
    public static function invalidateTokenCache($tokenId)
    {
        $cacheConfig = config('sanctum.token_cache');
        $cacheKey = $cacheConfig['prefix'] . $tokenId;

        Cache::store($cacheConfig['store'])->forget($cacheKey);
    }

    #[ArrayShape([
        'user_agent'              => "null|string",
        'client_type'             => "array|null|string",
        'client_name'             => "array|null|string",
        'client_version'          => "array|null|string",
        'device_operating_system' => "null|string",
        'device_name'             => "null|string",
    ])]
    public static function prepareDeviceFromRequest(Request $request): array
    {
        $deviceDetector = Device::detectRequest($request);

        $osName = $deviceDetector->getOs('name');
        if ($osName === 'UNK') {
            $osName = null;
        }
        $osVersion = $deviceDetector->getOs('version');
        if ($osVersion === 'UNK') {
            $osVersion = null;
        }

        if ($osName === 'Windows' && $osVersion === '10') {
            $osVersion = '>=10';
        }

        $deviceName = $deviceDetector->getDeviceName();

        return [
            'user_agent'              => $request->userAgent(),
            'client_type'             => $deviceDetector->getClient('type'),
            'client_name'             => $deviceDetector->getClient('name'),
            'client_version'          => $deviceDetector->getClient('version'),
            'device_operating_system' => $osName || $osVersion ? implode('|', [$osName, $osVersion]) : null,
            'device_name'             => $deviceName ?: null,
        ];
    }

    /**
     * @param Builder $q
     */
    protected function scopeWhereExpired($q)
    {
        return $q->where('expires_at', '<', now());
    }

    /**
     * Get the expiration time as a Carbon instance.
     */
    protected function expiresAt(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? new \Carbon\Carbon($value) : null,
        );
    }
}