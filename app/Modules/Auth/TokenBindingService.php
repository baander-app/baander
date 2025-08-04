<?php

namespace App\Modules\Auth;

use App\Http\HeaderExt;
use App\Models\PersonalAccessToken;
use App\Modules\Logging\Attributes\LogChannel;
use App\Modules\Logging\Channel;
use App\Notifications\SuspiciousLocationNotification;
use App\Notifications\ConcurrentAccessNotification;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Psr\Log\LoggerInterface;

class TokenBindingService
{
    /** @noinspection PhpPropertyOnlyWrittenInspection */
    #[LogChannel(Channel::Security)]
    private readonly LoggerInterface $logger;
    
    public function __construct(
        private readonly GeoLocationService $geoLocationService,
    ) {}

    public function generateClientFingerprint(Request $request): string
    {
        $components = [
            $request->userAgent() ?? '',
            $request->header('Accept-Language') ?? '',
            $request->header('Accept-Encoding') ?? '',
            $request->header('Accept') ?? '',
        ];

        return hash('sha256', implode('|', $components));
    }

    public function generateSessionId(): string
    {
        return Str::random(40);
    }

    public function validateTokenBinding(
        PersonalAccessToken $token,
        Request             $request,
    ): array
    {
        $currentFingerprint = $this->generateClientFingerprint($request);
        $currentIpAddress = $request->ip();
        $currentSessionId = $request->header(HeaderExt::X_BAANDER_SESSION_ID->value);

        $result = [
            'valid'  => true,
            'reason' => null,
            'action' => null,
        ];

        // Check for concurrent IP usage first (most critical security check)
        $concurrentCheck = $this->checkConcurrentIpUsage($token, $currentIpAddress);
        if (!$concurrentCheck['valid']) {
            return $concurrentCheck;
        }

        // Fingerprint must match exactly
        if ($token->client_fingerprint !== $currentFingerprint) {
            $this->logger->warning('Token fingerprint mismatch', [
                'user_id' => $token->tokenable_id,
                'token_id' => $token->id,
                'stored_fingerprint' => substr($token->client_fingerprint, 0, 16) . '...',
                'current_fingerprint' => substr($currentFingerprint, 0, 16) . '...',
            ]);

            $result['valid'] = false;
            $result['reason'] = 'fingerprint_mismatch';
            return $result;
        }

        // Session ID must match
        if ($token->session_id !== $currentSessionId) {
            $this->logger->warning('Token session ID mismatch', [
                'user_id' => $token->tokenable_id,
                'token_id' => $token->id,
                'has_stored_session' => !empty($token->session_id),
                'has_current_session' => !empty($currentSessionId),
            ]);

            $result['valid'] = false;
            $result['reason'] = 'session_mismatch';
            return $result;
        }

        // Handle IP address changes
        $ipValidation = $this->validateIpAddress($token, $currentIpAddress, $request);
        if (!$ipValidation['valid']) {
            return $ipValidation;
        }

        // Track this IP usage for concurrent detection
        $this->trackTokenIpUsage($token, $currentIpAddress);

        return $result;
    }

    /**
     * Check if token is being used from multiple IPs concurrently
     * This is our primary defense against token theft
     */
    private function checkConcurrentIpUsage(PersonalAccessToken $token, string $currentIp): array
    {
        $cacheKey = "token_ip_usage:{$token->id}";
        $concurrencyWindow = config('auth.token_binding.concurrent_ip_window_seconds', 300); // 5 minutes
        $maxConcurrentIps = config('auth.token_binding.max_concurrent_ips', 1); // Only 1 concurrent IP allowed

        // Get recent IP usage from cache
        $recentIps = Cache::get($cacheKey, []);
        $now = now();

        // Clean old entries (older than concurrency window)
        $recentIps = array_filter($recentIps, function($entry) use ($now, $concurrencyWindow) {
            return Carbon::parse($entry['last_seen'])->diffInSeconds($now) <= $concurrencyWindow;
        });

        // Get unique IPs that are not the current one
        $otherActiveIps = collect($recentIps)
            ->where('ip', '!=', $currentIp)
            ->pluck('ip')
            ->unique()
            ->values()
            ->toArray();

        // If we have too many concurrent IPs, this is suspicious
        if (count($otherActiveIps) >= $maxConcurrentIps) {
            $this->logger->critical('SECURITY BREACH: Concurrent IP usage detected - possible token theft', [
                'user_id' => $token->tokenable_id,
                'token_id' => $token->id,
                'current_ip' => $currentIp,
                'concurrent_ips' => $otherActiveIps,
                'window_seconds' => $concurrencyWindow,
                'user_agent' => request()->userAgent(),
            ]);

            // Send immediate critical notification
            $this->sendConcurrentAccessNotification($token, $currentIp, $otherActiveIps);

            // Revoke all user tokens immediately for security
            $this->revokeAllUserTokens($token->tokenable_id, 'concurrent_ip_usage');

            return [
                'valid' => false,
                'reason' => 'concurrent_ip_usage',
                'action' => 'revoke_all_tokens',
            ];
        }

        return ['valid' => true];
    }

    /**
     * Track token usage by IP for concurrent detection
     */
    private function trackTokenIpUsage(PersonalAccessToken $token, string $ip): void
    {
        $cacheKey = "token_ip_usage:{$token->id}";
        $ttl = config('auth.token_binding.concurrent_ip_window_seconds', 300);

        $recentIps = Cache::get($cacheKey, []);

        // Update or add current IP usage
        $recentIps[$ip] = [
            'ip' => $ip,
            'last_seen' => now()->toISOString(),
            'user_agent' => request()->userAgent(),
        ];

        // Store with TTL
        Cache::put($cacheKey, $recentIps, $ttl);
    }

    /**
     * Send critical notification for concurrent access attempt
     */
    private function sendConcurrentAccessNotification(
        PersonalAccessToken $token,
        string $currentIp,
        array $concurrentIps
    ): void {
        try {
            $user = $token->tokenable;
            $user->notify(new ConcurrentAccessNotification(
                $currentIp,
                $concurrentIps,
                request()->userAgent() ?? 'Unknown',
            ));

            $this->logger->critical('Concurrent access notification sent', [
                'user_id' => $user->id,
                'token_id' => $token->id,
                'current_ip' => $currentIp,
                'concurrent_ips' => $concurrentIps,
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to send concurrent access notification', [
                'user_id' => $token->tokenable_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Revoke all tokens for a user (security breach response)
     */
    private function revokeAllUserTokens(int $userId, string $reason): void
    {
        try {
            // Get all tokens before deletion for cache cleanup
            $userTokens = PersonalAccessToken::where('tokenable_id', $userId)
                ->where('tokenable_type', 'App\Models\User')
                ->get();

            // Delete all tokens
            $revokedCount = PersonalAccessToken::where('tokenable_id', $userId)
                ->where('tokenable_type', 'App\Models\User')
                ->delete();

            $this->logger->critical('SECURITY: All user tokens revoked due to security breach', [
                'user_id' => $userId,
                'reason' => $reason,
                'tokens_revoked' => $revokedCount,
                'timestamp' => now()->toISOString(),
            ]);

            // Clear token caches and tracking data
            foreach ($userTokens as $token) {
                PersonalAccessToken::invalidateTokenCache($token->id);
                Cache::forget("token_ip_usage:{$token->id}");
            }

        } catch (Exception $e) {
            $this->logger->error('Failed to revoke user tokens', [
                'user_id' => $userId,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function validateIpAddress(
        PersonalAccessToken $token,
        string              $currentIpAddress,
        Request             $request,
    ): array
    {
        $result = ['valid' => true, 'reason' => null, 'action' => null];

        // If IP hasn't changed, no need to validate further
        if ($token->ip_address === $currentIpAddress) {
            return $result;
        }

        // Check for rapid IP changes (possible attack)
        if ($this->isRapidIpChange($token, $currentIpAddress)) {
            $this->logger->warning('SECURITY: Rapid IP changes detected - possible attack', [
                'user_id' => $token->tokenable_id,
                'token_id' => $token->id,
                'current_ip' => $currentIpAddress,
                'previous_ip' => $token->ip_address,
                'last_change' => $token->updated_at,
                'minutes_since_change' => $token->updated_at ? $token->updated_at->diffInMinutes(now()) : 'unknown',
            ]);

            $result['valid'] = false;
            $result['reason'] = 'rapid_ip_changes';
            $result['action'] = 'logout';
            return $result;
        }

        // Get current location data
        $currentLocationData = $this->geoLocationService->getLocationData($currentIpAddress);

        // Check for suspicious geographic jumps
        if ($this->isSuspiciousGeoJump($token, $currentLocationData)) {
            $this->logger->warning('SECURITY: Suspicious geographic jump detected', [
                'user_id' => $token->tokenable_id,
                'token_id' => $token->id,
                'from_country' => $token->country_code,
                'to_country' => $currentLocationData['country_code'] ?? 'unknown',
                'time_since_last_change' => $token->updated_at ? $token->updated_at->diffInMinutes(now()) : 'unknown',
                'from_ip' => $token->ip_address,
                'to_ip' => $currentIpAddress,
            ]);

            $result['valid'] = false;
            $result['reason'] = 'suspicious_geo_jump';
            $result['action'] = 'logout';
            return $result;
        }

        // Check if user has exceeded maximum IP changes
        if ($token->ip_change_count >= config('auth.token_binding.max_ip_changes', 10)) {
            $this->logger->warning('Token exceeded maximum IP changes', [
                'user_id'    => $token->tokenable_id,
                'token_id'   => $token->id,
                'ip_changes' => $token->ip_change_count,
                'current_ip' => $currentIpAddress,
                'stored_ip'  => $token->ip_address,
                'max_allowed' => config('auth.token_binding.max_ip_changes', 10),
            ]);

            $result['valid'] = false;
            $result['reason'] = 'max_ip_changes_exceeded';
            $result['action'] = 'logout';
            return $result;
        }

        // Check for country change and send notification if needed
        if ($this->shouldNotifyGeoChange($token, $currentLocationData)) {
            $this->sendGeoChangeNotification($token, $currentLocationData, $currentIpAddress, $request);
        }

        // Update token with new IP information
        $this->updateTokenIpData($token, $currentIpAddress, $currentLocationData);

        return $result;
    }

    /**
     * Check if IP is changing too rapidly (possible attack)
     */
    private function isRapidIpChange(PersonalAccessToken $token, string $newIp): bool
    {
        if (!$token->updated_at || $token->ip_address === $newIp) {
            return false;
        }

        $minChangeInterval = config('auth.token_binding.min_ip_change_interval_minutes', 5);
        $minutesSinceLastChange = $token->updated_at->diffInMinutes(now());

        return $minutesSinceLastChange < $minChangeInterval;
    }

    /**
     * Check for suspicious geographic jumps (too far, too fast)
     */
    private function isSuspiciousGeoJump(PersonalAccessToken $token, array $newLocationData): bool
    {
        if (!$token->country_code || !$token->updated_at || empty($newLocationData['country_code'])) {
            return false;
        }

        // If same country, not suspicious
        if ($token->country_code === $newLocationData['country_code']) {
            return false;
        }

        // Check if the change happened too quickly for realistic travel
        $hoursSinceLastChange = $token->updated_at->diffInHours(now());
        $suspiciousJumpHours = config('auth.token_binding.suspicious_geo_jump_hours', 2);

        // If country changed within suspicious timeframe, it's suspicious
        return $hoursSinceLastChange < $suspiciousJumpHours;
    }

    private function shouldNotifyGeoChange(
        PersonalAccessToken $token,
        array               $currentLocationData,
    ): bool
    {
        // Don't notify for private IPs
        if ($currentLocationData['is_private'] ?? true) {
            return false;
        }

        // Check if country has changed
        $hasCountryChanged = $this->geoLocationService->hasCountryChanged(
            $token->country_code ?? '',
            $currentLocationData['country_code'] ?? '',
        );

        if (!$hasCountryChanged) {
            return false;
        }

        // Check cooldown period
        if ($token->last_geo_notification_at) {
            $cooldownEnd = $token->last_geo_notification_at->addSeconds(
                config('auth.token_binding.geo_change_cooldown_seconds', 3600)
            );
            if (now()->lt($cooldownEnd)) {
                return false;
            }
        }

        return true;
    }

    private function sendGeoChangeNotification(
        PersonalAccessToken $token,
        array               $locationData,
        string              $ipAddress,
        Request             $request,
    ): void
    {
        try {
            $user = $token->tokenable;
            $user->notify(new SuspiciousLocationNotification(
                $locationData,
                $ipAddress,
                $request->userAgent() ?? 'Unknown',
            ));

            // Update notification timestamp
            $token->update(['last_geo_notification_at' => now()]);

            $this->logger->info('Geo-location change notification sent', [
                'user_id'     => $user->id,
                'token_id'    => $token->id,
                'old_country' => $token->country_code,
                'new_country' => $locationData['country_code'] ?? 'unknown',
                'ip_address'  => $ipAddress,
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to send geo-location notification', [
                'user_id' => $token->tokenable_id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function updateTokenIpData(
        PersonalAccessToken $token,
        string              $currentIpAddress,
        array               $locationData,
    ): void
    {
        $ipHistory = $token->ip_history ? json_decode($token->ip_history, true) : [];

        // Add current IP to history with more details
        $ipHistory[] = [
            'ip'         => $currentIpAddress,
            'timestamp'  => now()->toISOString(),
            'location'   => $locationData,
            'user_agent' => request()->userAgent(),
        ];

        // Keep only last 10 IP entries
        $ipHistory = array_slice($ipHistory, -10);

        $token->update([
            'ip_address'      => $currentIpAddress,
            'ip_history'      => json_encode($ipHistory),
            'ip_change_count' => ($token->ip_change_count ?? 0) + 1,
            'country_code'    => $locationData['country_code'] ?? null,
            'city'            => $locationData['city'] ?? null,
        ]);

        $this->logger->info('Token IP data updated', [
            'user_id'         => $token->tokenable_id,
            'token_id'        => $token->id,
            'new_ip'          => $currentIpAddress,
            'ip_change_count' => ($token->ip_change_count ?? 0) + 1,
            'country'         => $locationData['country_code'] ?? 'unknown',
        ]);
    }

    /**
     * Clean up expired tracking data (call this from a scheduled job)
     */
    public function cleanupExpiredTrackingData(): int
    {
        $pattern = "token_ip_usage:*";
        $keys = Cache::getRedis()->keys($pattern);
        $cleaned = 0;

        foreach ($keys as $key) {
            $data = Cache::get($key);
            if (!$data || empty($data)) {
                Cache::forget($key);
                $cleaned++;
            }
        }

        $this->logger->info('Token binding tracking data cleanup completed', [
            'keys_cleaned' => $cleaned
        ]);

        return $cleaned;
    }

    /**
     * Get security statistics for monitoring
     */
    public function getSecurityStats(): array
    {
        $pattern = "token_ip_usage:*";
        $keys = Cache::getRedis()->keys($pattern);

        return [
            'active_tokens_tracked' => count($keys),
            'concurrent_window_seconds' => config('auth.token_binding.concurrent_ip_window_seconds', 300),
            'max_concurrent_ips' => config('auth.token_binding.max_concurrent_ips', 1),
            'max_ip_changes' => config('auth.token_binding.max_ip_changes', 10),
        ];
    }
}