<?php

namespace App\Http\Controllers\Api\Services;

use App\Http\Controllers\Controller;
use App\Http\Integrations\LastFm\LastFmClient;
use App\Models\ThirdPartyCredential;
use App\Models\TokenAbility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\RouteAttributes\Attributes\{Get, Post, Prefix};


#[Prefix('services/lastfm')]
class LastFmController extends Controller
{
    public function __construct(
        private readonly LastFmClient $lastFmClient,
    )
    {
    }

    #[Get('authorize', 'lastfm.authorize', [
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_API->value,
    ])]
    public function authorizeLastFm(Request $request)
    {
        $token = $this->lastFmClient->auth->getToken();

        if (!$token) {
            return response()->json(['error' => 'Failed to get Last.fm token'])->setStatusCode(500);
        }

        // Generate state and nonce
        $state = Str::random(40);
        $nonce = Str::random(32);

        // Store the state with user information and validation data
        Cache::put("lastfm_auth_state_{$state}", [
            'user_id'       => auth()->id(),
            'initial_token' => $token,
            'nonce'         => $nonce,
            'user_agent'    => $request->userAgent(),
            'ip_address'    => $request->ip(),
            'expires_at'    => now()->addMinutes(10),
        ], now()->addMinutes(10));

        $callbackUrl = route('lastfm.callback', [
            'state' => $state,
            'nonce' => $nonce,
        ]);

        $authUrl = $this->lastFmClient->auth->getAuthUrl($callbackUrl);

        return response()->json([
            'authUrl' => $authUrl,
        ]);
    }


    #[Get('callback', 'lastfm.callback')]
    public function callback(Request $request)
    {
        $newToken = $request->query('token'); // This is the new token from Last.fm
        $state = $request->query('state');
        $nonce = $request->query('nonce');

        if (!$newToken || !$state || !$nonce) {
            return response()->json(['error' => 'Missing required parameters'])->setStatusCode(400);
        }

        // Use atomic operation to get and delete in one operation
        $authState = Cache::pull("lastfm_auth_state_{$state}");

        if (!$authState) {
            return response()->json(['error' => 'Invalid, expired, or already used authorization state'])->setStatusCode(400);
        }

        // Validate the nonce matches what we stored
        if (!hash_equals($authState['nonce'], $nonce)) {
            // State is already removed by Cache::pull(), so no cleanup needed
            return response()->json(['error' => 'Invalid nonce'])->setStatusCode(400);
        }

        // Optional: Additional security checks
        if ($authState['user_agent'] !== $request->userAgent()) {
            Cache::forget("lastfm_auth_state_{$state}");
            return response()->json(['error' => 'User agent mismatch'])->setStatusCode(400);
        }

        if ($authState['ip_address'] !== $request->ip()) {
            Cache::forget("lastfm_auth_state_{$state}");
            return response()->json(['error' => 'IP address mismatch'])->setStatusCode(400);
        }

        // Clean up the state from cache (important to prevent replay attacks)
        Cache::forget("lastfm_auth_state_{$state}");

        // Continue with Last.fm session creation using the new token
        $session = $this->lastFmClient->auth->getSession($newToken);

        if (!$session) {
            return response()->json(['error' => 'Failed to get Last.fm session'])->setStatusCode(500);
        }

        $userInfo = $this->lastFmClient->auth->getUserInfo($session['key']);

        // Store credentials using the validated user_id
        $metaData = [
            'session_key'       => $session['key'],
            'provider_username' => $session['name'],
            'provider_user_id'  => $userInfo['id'] ?? null,
            'playcount'         => $userInfo['playcount'] ?? 0,
            'registered'        => $userInfo['registered']['#text'] ?? null,
            'country'           => $userInfo['country'] ?? null,
            'age'               => $userInfo['age'] ?? null,
            'gender'            => $userInfo['gender'] ?? null,
            'subscriber'        => $userInfo['subscriber'] ?? '0',
            'realname'          => $userInfo['realname'] ?? null,
            'url'               => $userInfo['url'] ?? null,
            'image'             => $userInfo['image'] ?? [],
        ];

        ThirdPartyCredential::updateOrCreate(
            [
                'user_id'  => $authState['user_id'],
                'provider' => 'lastfm',
            ],
            [
                'meta' => $metaData,
            ],
        );

        Log::info('Last.fm user connected', [
            'user_id'          => $authState['user_id'],
            'lastfm_username'  => $session['name'],
            'lastfm_playcount' => $userInfo['playcount'] ?? 0,
            'state'            => $state,
            'nonce_validated'  => true,
        ]);

        return response()->json(['success' => true]);
    }

    #[Post('disconnect', 'lastfm.disconnect', [
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_API->value,
    ])]
    public function disconnect()
    {
        ThirdPartyCredential::where('user_id', auth()->id())
            ->where('provider', 'lastfm')
            ->delete();

        return response()->json(['success' => true]);
    }

    #[Get('status', 'lastfm.status',
        ['auth:sanctum',
         'ability:' . TokenAbility::ACCESS_API->value,
        ])]
    public function status()
    {
        $credential = auth()->user()->getLastFmCredential();

        if (!$credential) {
            return response()->json(['connected' => false]);
        }

        $isValid = $this->lastFmClient->auth->validateSession($credential->getSessionKey());

        if (!$isValid) {
            $credential->delete();
            return response()->json(['connected' => false, 'expired' => true]);
        }

        return response()->json([
            'connected' => true,
            'username'  => $credential->getProviderUsername(),
            'data'      => $credential->getLastFmData(),
        ]);
    }

}
