<?php

namespace App\Modules\Auth;

use App\Http\HeaderExt;
use App\Models\Auth\OAuth\Token;
use App\Models\Auth\OAuth\TokenMetadata;
use App\Modules\Logging\Attributes\LogChannel;
use App\Modules\Logging\Channel;
use App\Notifications\ConcurrentAccessNotification;
use App\Notifications\SuspiciousLocationNotification;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
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

    /**
     * Validate token binding for OAuth tokens with metadata
     */
    public function validateTokenBinding(
        Token $token,
        Request $request,
    ): array {
        // Skip if no metadata (third-party OAuth tokens)
        $metadata = $token->metadata;
        if (!$metadata || !$metadata->client_fingerprint) {
            return ['valid' => true];
        }

        $currentFingerprint = $this->generateClientFingerprint($request);
        $currentIpAddress = $request->ip();
        $currentSessionId = $request->header(HeaderExt::X_BAANDER_SESSION_ID->value);

        $result = [
            'valid' => true,
            'reason' => null,
            'action' => null,
        ];

        // Check for concurrent IP usage first (most critical security check)
        $concurrentCheck = $this->checkConcurrentIpUsage($token, $currentIpAddress);
        if (!$concurrentCheck['valid']) {
            return $concurrentCheck;
        }

        // Fingerprint must match exactly
        if ($metadata->client_fingerprint !== $currentFingerprint) {
            $this->logger->warning('Token fingerprint mismatch', [
                'user_id' => $token->user_id,
                'token_id' => $token->token_id,
                'stored_fingerprint' => substr($metadata->client_fingerprint, 0, 16) . '...',
                'current_fingerprint' => substr($currentFingerprint, 0, 16) . '...',
            ]);

            $result['valid'] = false;
            $result['reason'] = 'fingerprint_mismatch';
            return $result;
        }

        // Session ID must match
        if ($metadata->session_id !== $currentSessionId) {
            $this->logger->warning('Token session ID mismatch', [
                'user_id' => $token->user_id,
                'token_id' => $token->token_id,
                'has_stored_session' => !empty($metadata->session_id),
                'has_current_session' => !empty($currentSessionId),
            ]);

            $result['valid'] = false;
            $result['reason'] = 'session_mismatch';
            return $result;
        }

        // Handle IP address changes
        $ipValidation = $this->validateIpAddress($metadata, $currentIpAddress, $request);
        if (!$ipValidation['valid']) {
            return $ipValidation;
        }

        // Track this IP usage for concurrent detection
        $this->trackTokenIpUsage($token, $currentIpAddress);

        return $result;
    }

    /**
     * Check if token is being used from multiple IPs concurrently
     */
    private function checkConcurrentIpUsage(Token $token, string $currentIp): array
    {
        $cacheKey = "token_ip_usage:{$token->token_id}";
        $concurrencyWindow = config('auth.token_binding.concurrent_ip_window_seconds', 300);
        $maxConcurrentIps = config('auth.token_binding.max_concurrent_ips', 1);

        $recentIps = Cache::get($cacheKey, []);
        $now = now();

        // Clean old entries
        $recentIps = array_filter($recentIps, function($entry) use ($now, $concurrencyWindow) {
            return Carbon::parse($entry['last_seen'])->diffInSeconds($now) <= $concurrencyWindow;
        });

        $otherActiveIps = collect($recentIps)
            ->where('ip', '!=', $currentIp)
            ->pluck('ip')
            ->unique()
            ->values()
            ->toArray();

        if (count($otherActiveIps) >= $maxConcurrentIps) {
            $this->logger->critical('SECURITY BREACH: Concurrent IP usage detected', [
                'user_id' => $token->user_id,
                'token_id' => $token->token_id,
                'current_ip' => $currentIp,
                'concurrent_ips' => $otherActiveIps,
                'window_seconds' => $concurrencyWindow,
                'user_agent' => request()->userAgent(),
            ]);

            $this->sendConcurrentAccessNotification($token, $currentIp, $otherActiveIps);
            $this->revokeAllUserTokens($token->user_id, 'concurrent_ip_usage');

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
    private function trackTokenIpUsage(Token $token, string $ip): void
    {
        $cacheKey = "token_ip_usage:{$token->token_id}";
        $ttl = config('auth.token_binding.concurrent_ip_window_seconds', 300);

        $recentIps = Cache::get($cacheKey, []);

        $recentIps[$ip] = [
            'ip' => $ip,
            'last_seen' => now()->toISOString(),
            'user_agent' => request()->userAgent(),
        ];

        Cache::put($cacheKey, $recentIps, $ttl);
    }

    /**
     * Send notification for concurrent access
     */
    private function sendConcurrentAccessNotification(
        Token $token,
        string $currentIp,
        array $concurrentIps
    ): void {
        try {
            $user = $token->user;
            $user->notify(new ConcurrentAccessNotification(
                $currentIp,
                $concurrentIps,
                request()->userAgent() ?? 'Unknown',
            ));

            $this->logger->critical('Concurrent access notification sent', [
                'user_id' => $user->id,
                'token_id' => $token->token_id,
                'current_ip' => $currentIp,
                'concurrent_ips' => $concurrentIps,
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to send concurrent access notification', [
                'user_id' => $token->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Revoke all tokens for a user
     */
    private function revokeAllUserTokens(int $userId, string $reason): void
    {
        try {
            $userTokens = Token::where('user_id', $userId)->get();

            $revokedCount = Token::where('user_id', $userId)->update(['revoked' => true]);

            $this->logger->critical('SECURITY: All user tokens revoked', [
                'user_id' => $userId,
                'reason' => $reason,
                'tokens_revoked' => $revokedCount,
                'timestamp' => now()->toISOString(),
            ]);

            foreach ($userTokens as $token) {
                Cache::forget("token_ip_usage:{$token->token_id}");
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to revoke user tokens', [
                'user_id' => $userId,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Validate IP address changes
     */
    private function validateIpAddress(
        TokenMetadata $metadata,
        string $currentIpAddress,
        Request $request,
    ): array {
        $result = ['valid' => true, 'reason' => null, 'action' => null];

        if ($metadata->ip_address === $currentIpAddress) {
            return $result;
        }

        if ($this->isRapidIpChange($metadata, $currentIpAddress)) {
            $this->logger->warning('SECURITY: Rapid IP changes detected', [
                'token_id' => $metadata->token_id,
                'current_ip' => $currentIpAddress,
                'previous_ip' => $metadata->ip_address,
                'minutes_since_change' => $metadata->updated_at ? $metadata->updated_at->diffInMinutes(now()) : 'unknown',
            ]);

            $result['valid'] = false;
            $result['reason'] = 'rapid_ip_changes';
            $result['action'] = 'logout';
            return $result;
        }

        $currentLocationData = $this->geoLocationService->getLocationData($currentIpAddress);

        if ($this->isSuspiciousGeoJump($metadata, $currentLocationData)) {
            $this->logger->warning('SECURITY: Suspicious geographic jump detected', [
                'token_id' => $metadata->token_id,
                'from_country' => $metadata->country_code,
                'to_country' => $currentLocationData['country_code'] ?? 'unknown',
                'from_ip' => $metadata->ip_address,
                'to_ip' => $currentIpAddress,
            ]);

            $result['valid'] = false;
            $result['reason'] = 'suspicious_geo_jump';
            $result['action'] = 'logout';
            return $result;
        }

        if ($metadata->ip_change_count >= config('auth.token_binding.max_ip_changes', 10)) {
            $this->logger->warning('Token exceeded maximum IP changes', [
                'token_id' => $metadata->token_id,
                'ip_changes' => $metadata->ip_change_count,
                'current_ip' => $currentIpAddress,
                'stored_ip' => $metadata->ip_address,
                'max_allowed' => config('auth.token_binding.max_ip_changes', 10),
            ]);

            $result['valid'] = false;
            $result['reason'] = 'max_ip_changes_exceeded';
            $result['action'] = 'logout';
            return $result;
        }

        if ($this->shouldNotifyGeoChange($metadata, $currentLocationData)) {
            $this->sendGeoChangeNotification($metadata, $currentLocationData, $currentIpAddress, $request);
        }

        $this->updateTokenIpData($metadata, $currentIpAddress, $currentLocationData);

        return $result;
    }

    private function isRapidIpChange(TokenMetadata $metadata, string $newIp): bool
    {
        if (!$metadata->updated_at || $metadata->ip_address === $newIp) {
            return false;
        }

        $minChangeInterval = config('auth.token_binding.min_ip_change_interval_minutes', 5);
        $minutesSinceLastChange = $metadata->updated_at->diffInMinutes(now());

        return $minutesSinceLastChange < $minChangeInterval;
    }

    private function isSuspiciousGeoJump(TokenMetadata $metadata, array $newLocationData): bool
    {
        if (!$metadata->country_code || !$metadata->updated_at || empty($newLocationData['country_code'])) {
            return false;
        }

        if ($metadata->country_code === $newLocationData['country_code']) {
            return false;
        }

        $hoursSinceLastChange = $metadata->updated_at->diffInHours(now());
        $suspiciousJumpHours = config('auth.token_binding.suspicious_geo_jump_hours', 2);

        return $hoursSinceLastChange < $suspiciousJumpHours;
    }

    private function shouldNotifyGeoChange(
        TokenMetadata $metadata,
        array $currentLocationData,
    ): bool {
        if ($currentLocationData['is_private'] ?? true) {
            return false;
        }

        $hasCountryChanged = $this->geoLocationService->hasCountryChanged(
            $metadata->country_code ?? '',
            $currentLocationData['country_code'] ?? '',
        );

        if (!$hasCountryChanged) {
            return false;
        }

        if ($metadata->last_geo_notification_at) {
            $cooldownEnd = $metadata->last_geo_notification_at->addSeconds(
                config('auth.token_binding.geo_change_cooldown_seconds', 3600)
            );
            if (now()->lt($cooldownEnd)) {
                return false;
            }
        }

        return true;
    }

    private function sendGeoChangeNotification(
        TokenMetadata $metadata,
        array $locationData,
        string $ipAddress,
        Request $request,
    ): void {
        try {
            $token = $metadata->token;
            $user = $token->user;
            $user->notify(new SuspiciousLocationNotification(
                $locationData,
                $ipAddress,
                $request->userAgent() ?? 'Unknown',
            ));

            $metadata->update(['last_geo_notification_at' => now()]);

            $this->logger->info('Geo-location change notification sent', [
                'user_id' => $user->id,
                'token_id' => $token->token_id,
                'old_country' => $metadata->country_code,
                'new_country' => $locationData['country_code'] ?? 'unknown',
                'ip_address' => $ipAddress,
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to send geo-location notification', [
                'token_id' => $metadata->token_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function updateTokenIpData(
        TokenMetadata $metadata,
        string $currentIpAddress,
        array $locationData,
    ): void {
        $ipHistory = $metadata->ip_history ?? [];

        $ipHistory[] = [
            'ip' => $currentIpAddress,
            'timestamp' => now()->toISOString(),
            'location' => $locationData,
            'user_agent' => request()->userAgent(),
        ];

        $ipHistory = array_slice($ipHistory, -10);

        $metadata->update([
            'ip_address' => $currentIpAddress,
            'ip_history' => $ipHistory,
            'ip_change_count' => ($metadata->ip_change_count ?? 0) + 1,
            'country_code' => $locationData['country_code'] ?? null,
            'city' => $locationData['city'] ?? null,
        ]);

        $this->logger->info('Token IP data updated', [
            'token_id' => $metadata->token_id,
            'new_ip' => $currentIpAddress,
            'ip_change_count' => ($metadata->ip_change_count ?? 0) + 1,
            'country' => $locationData['country_code'] ?? 'unknown',
        ]);
    }

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