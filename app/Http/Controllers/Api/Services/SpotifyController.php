<?php

namespace App\Http\Controllers\Api\Services;

use App\Http\Controllers\Controller;
use App\Http\Integrations\Spotify\SpotifyClient;
use App\Models\ThirdPartyCredential;
use App\Models\TokenAbility;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\RouteAttributes\Attributes\{Get, Post, Prefix};

#[Prefix('services/spotify')]
class SpotifyController extends Controller
{
    public function __construct(
        private readonly SpotifyClient $spotifyClient,
    )
    {
    }

    #[Get('authorize', 'spotify.authorize', [
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_API->value,
    ])]
    public function authorizeSpotify(Request $request)
    {
        $clientId = config('services.spotify.client_id');
        $redirectUri = route('spotify.callback');

        if (!$clientId) {
            return response()->json(['error' => 'Spotify client ID not configured'])->setStatusCode(500);
        }

        // Generate state and nonce for security
        $state = Str::random(40);
        $nonce = Str::random(32);

        // Store the state with user information and validation data
        Cache::put("spotify_auth_state_{$state}", [
            'user_id'    => auth()->id(),
            'nonce'      => $nonce,
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
            'expires_at' => now()->addMinutes(10),
        ], now()->addMinutes(10));

        // Define the scopes we need
        $scopes = [
            'user-read-private',
            'user-read-email',
            'user-library-read',
            'user-library-modify',
            'user-read-playback-state',
            'user-modify-playback-state',
            'user-read-currently-playing',
            'user-read-recently-played',
            'user-top-read',
            'playlist-read-private',
            'playlist-read-collaborative',
            'playlist-modify-public',
            'playlist-modify-private',
        ];

        $authUrl = $this->spotifyClient->auth->getAuthorizationUrl(
            $clientId,
            $redirectUri,
            $state,
        );

        return response()->json([
            'authUrl' => $authUrl,
        ]);
    }

    #[Get('callback', 'spotify.callback')]
    public function callback(Request $request)
    {
        $code = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');

        if ($error) {
            return response()->json(['error' => 'Spotify authorization failed: ' . $error])->setStatusCode(400);
        }

        if (!$code || !$state) {
            return response()->json(['error' => 'Missing required parameters'])->setStatusCode(400);
        }

        // Use atomic operation to get and delete in one operation
        $authState = Cache::pull("spotify_auth_state_{$state}");

        if (!$authState) {
            return response()->json(['error' => 'Invalid, expired, or already used authorization state'])->setStatusCode(400);
        }

        // Optional: Additional security checks
        if ($authState['user_agent'] !== $request->userAgent()) {
            return response()->json(['error' => 'User agent mismatch'])->setStatusCode(400);
        }

        if ($authState['ip_address'] !== $request->ip()) {
            return response()->json(['error' => 'IP address mismatch'])->setStatusCode(400);
        }

        try {
            $clientId = config('services.spotify.client_id');
            $clientSecret = config('services.spotify.secret');
            $redirectUri = route('spotify.callback');

            // Exchange authorization code for access token
            $tokenData = $this->spotifyClient->auth->getAccessToken(
                $code,
                $clientId,
                $clientSecret,
                $redirectUri,
            );

            if (!isset($tokenData['access_token'])) {
                return response()->json(['error' => 'Failed to get access token'])->setStatusCode(500);
            }

            // Get user info using the access token
            $userInfo = $this->spotifyClient->withAccessToken($tokenData['access_token'])->user->getCurrentUser();

            // Store credentials
            $metaData = [
                'access_token'      => $tokenData['access_token'],
                'refresh_token'     => $tokenData['refresh_token'] ?? null,
                'token_type'        => $tokenData['token_type'] ?? 'Bearer',
                'expires_in'        => $tokenData['expires_in'] ?? 3600,
                'expires_at'        => now()->addSeconds($tokenData['expires_in'] ?? 3600)->timestamp,
                'scope'             => $tokenData['scope'] ?? '',
                'provider_user_id'  => $userInfo['id'] ?? null,
                'provider_username' => $userInfo['display_name'] ?? $userInfo['id'] ?? null,
                'email'             => $userInfo['email'] ?? null,
                'country'           => $userInfo['country'] ?? null,
                'followers'         => $userInfo['followers']['total'] ?? 0,
                'product'           => $userInfo['product'] ?? null,
                'images'            => $userInfo['images'] ?? [],
            ];

            ThirdPartyCredential::updateOrCreate(
                [
                    'user_id'  => $authState['user_id'],
                    'provider' => 'spotify',
                ],
                [
                    'meta' => $metaData,
                ],
            );

            Log::info('Spotify user connected', [
                'user_id'           => $authState['user_id'],
                'spotify_user_id'   => $userInfo['id'] ?? null,
                'spotify_username'  => $userInfo['display_name'] ?? null,
                'spotify_followers' => $userInfo['followers']['total'] ?? 0,
                'state'             => $state,
            ]);

            return response()->json(['success' => true]);

        } catch (Exception $e) {
            Log::error('Spotify callback error', [
                'error'   => $e->getMessage(),
                'user_id' => $authState['user_id'],
                'state'   => $state,
            ]);

            return response()->json(['error' => 'Failed to connect to Spotify'])->setStatusCode(500);
        }
    }

    #[Post('disconnect', 'spotify.disconnect', [
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_API->value,
    ])]
    public function disconnect()
    {
        ThirdPartyCredential::where('user_id', auth()->id())
            ->where('provider', 'spotify')
            ->delete();

        return response()->json(['success' => true]);
    }

    #[Get('status', 'spotify.status', [
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_API->value,
    ])]
    public function status()
    {
        $credential = auth()->user()->thirdPartyCredentials()
            ->where('provider', 'spotify')
            ->first();

        if (!$credential) {
            return response()->json(['connected' => false]);
        }

        $metaData = $credential->meta;

        // Check if token is expired
        if (isset($metaData['expires_at']) && time() >= $metaData['expires_at']) {
            // Try to refresh the token
            if (isset($metaData['refresh_token'])) {
                try {
                    $clientId = config('services.spotify.client_id');
                    $clientSecret = config('services.spotify.client_secret');

                    $tokenData = $this->spotifyClient->auth->refreshAccessToken(
                        $metaData['refresh_token'],
                        $clientId,
                        $clientSecret,
                    );

                    if (isset($tokenData['access_token'])) {
                        // Update the stored credentials
                        $metaData['access_token'] = $tokenData['access_token'];
                        $metaData['expires_in'] = $tokenData['expires_in'] ?? 3600;
                        $metaData['expires_at'] = now()->addSeconds($tokenData['expires_in'] ?? 3600)->timestamp;

                        if (isset($tokenData['refresh_token'])) {
                            $metaData['refresh_token'] = $tokenData['refresh_token'];
                        }

                        $credential->update(['meta' => $metaData]);
                    } else {
                        $credential->delete();
                        return response()->json(['connected' => false, 'expired' => true]);
                    }
                } catch (Exception $e) {
                    Log::error('Spotify token refresh failed', [
                        'error'   => $e->getMessage(),
                        'user_id' => auth()->id(),
                    ]);

                    $credential->delete();
                    return response()->json(['connected' => false, 'expired' => true]);
                }
            } else {
                $credential->delete();
                return response()->json(['connected' => false, 'expired' => true]);
            }
        }

        return response()->json([
            'connected' => true,
            'username'  => $metaData['provider_username'] ?? null,
            'data'      => [
                'spotify_user_id' => $metaData['provider_user_id'] ?? null,
                'email'           => $metaData['email'] ?? null,
                'country'         => $metaData['country'] ?? null,
                'followers'       => $metaData['followers'] ?? 0,
                'product'         => $metaData['product'] ?? null,
                'images'          => $metaData['images'] ?? [],
            ],
        ]);
    }

    #[Get('user/profile', 'spotify.user.profile', [
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_API->value,
    ])]
    public function getUserProfile()
    {
        if (!$this->spotifyClient->hasValidCredentials()) {
            return response()->json(['error' => 'Spotify not connected'])->setStatusCode(401);
        }

        try {
            $profile = $this->spotifyClient->user->getCurrentUser();
            return response()->json($profile);
        } catch (Exception $e) {
            return response()->json(['error' => 'Failed to get user profile'])->setStatusCode(500);
        }
    }

    #[Get('user/playlists', 'spotify.user.playlists', [
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_API->value,
    ])]
    public function getUserPlaylists(Request $request)
    {
        if (!$this->spotifyClient->hasValidCredentials()) {
            return response()->json(['error' => 'Spotify not connected'])->setStatusCode(401);
        }

        try {
            $limit = $request->query('limit', 20);
            $offset = $request->query('offset', 0);

            $playlists = $this->spotifyClient->user->getUserPlaylists(null, $limit, $offset);
            return response()->json($playlists);
        } catch (Exception $e) {
            return response()->json(['error' => 'Failed to get playlists'])->setStatusCode(500);
        }
    }

    #[Get('search', 'spotify.search', [
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_API->value,
    ])]
    public function search(Request $request)
    {
        if (!$this->spotifyClient->hasValidCredentials()) {
            return response()->json(['error' => 'Spotify not connected'])->setStatusCode(401);
        }

        $query = $request->query('q');
        if (!$query) {
            return response()->json(['error' => 'Query parameter is required'])->setStatusCode(400);
        }

        try {
            $types = explode(',', $request->query('type', 'track'));
            $limit = $request->query('limit', 20);
            $offset = $request->query('offset', 0);
            $market = $request->query('market');

            $results = $this->spotifyClient->search->search($query, $types, $limit, $offset, $market);
            return response()->json($results);
        } catch (Exception $e) {
            return response()->json(['error' => 'Search failed'])->setStatusCode(500);
        }
    }

    #[Get('genres/seeds', 'spotify.genres.seeds', [
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_API->value,
    ])]
    public function getGenreSeeds()
    {
        if (!$this->spotifyClient->hasValidCredentials()) {
            return response()->json(['error' => 'Spotify not connected'])->setStatusCode(401);
        }

        try {
            $genres = $this->spotifyClient->genres->getAvailableGenreSeeds();
            return response()->json($genres);
        } catch (Exception $e) {
            return response()->json(['error' => 'Failed to get genre seeds'])->setStatusCode(500);
        }
    }
}
