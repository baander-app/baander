<?php

namespace App\Modules\Apm\Listeners;

use App\Modules\Apm\OctaneApmManager;
use Elastic\Apm\SpanContextInterface;
use Elastic\Apm\SpanInterface;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Request;
use Psr\Log\LoggerInterface;

class AuthenticationListener
{
    public function __construct(
        private ?LoggerInterface $logger = null,
    )
    {
    }

    /**
     * Handle authentication attempting event
     */
    public function handleAttempting(Attempting $event): void
    {
        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            $span = $manager->createSpan(
                'Authentication Attempt',
                'auth',
                'attempt',
                'security',
            );

            if ($span) {
                $this->setAuthAttemptContext($span, $event);
                $this->addAuthAttemptTags($manager, $span, $event);
                $span->end();
            }

            // Add transaction-level context
            $this->addAuthTransactionContext($manager, 'attempt', $event->credentials);

        } catch (\Throwable $e) {
            $this->logger?->error('Failed to track auth attempt', [
                'exception'            => $e->getMessage(),
                'guard'                => $event->guard,
                'credentials_provided' => array_keys($event->credentials),
            ]);
        }
    }

    /**
     * Set authentication attempt context
     */
    private function setAuthAttemptContext($span, Attempting $event): void
    {
        $spanContext = $span->context();

        // Core auth context
        $spanContext->setLabel('auth.guard', $event->guard);
        $spanContext->setLabel('auth.action', 'attempt');
        $spanContext->setLabel('auth.credentials_provided', json_encode(array_keys($event->credentials)));
        $spanContext->setLabel('auth.remember', $event->remember ? 'true' : 'false');

        // Request context
        $this->setRequestContext($spanContext);

        // Security context
        $this->setSecurityContext($spanContext);

        $span->setOutcome('unknown'); // Will be determined by result
    }

    /**
     * Set request context
     */
    private function setRequestContext(SpanContextInterface $spanContext): void
    {
        $spanContext->setLabel('http.method', Request::method());
        $spanContext->setLabel('http.url', Request::url());
        $spanContext->setLabel('http.user_agent', Request::userAgent());
        $spanContext->setLabel('http.referer', Request::header('referer', 'direct'));

        // Client context
        $spanContext->setLabel('client.ip', Request::ip());
        $spanContext->setLabel('client.geo.country', $this->getCountryFromIp(Request::ip()));
    }

    /**
     * Get country from IP (mock implementation)
     */
    private function getCountryFromIp(string $ip): string
    {
        // Implement IP geolocation logic or use a service
        return 'unknown';
    }

    /**
     * Set security context
     */
    private function setSecurityContext(SpanContextInterface $spanContext): void
    {
        $spanContext->setLabel('security.ip_address', Request::ip());
        $spanContext->setLabel('security.user_agent_hash', hash('sha256', Request::userAgent() ?? ''));
        $spanContext->setLabel('security.timestamp', now()->toISOString());
        $spanContext->setLabel('security.suspicious_activity', $this->detectSuspiciousActivity());
    }

    /**
     * Detect suspicious activity
     */
    private function detectSuspiciousActivity(): string
    {
        // Implement suspicious activity detection logic
        return 'false';
    }

    /**
     * Add authentication attempt tags
     */
    private function addAuthAttemptTags(OctaneApmManager $manager, $span, Attempting $event): void
    {
        $manager->addSpanTag($span, 'auth.guard', $event->guard);
        $manager->addSpanTag($span, 'auth.action', 'attempt');
        $manager->addSpanTag($span, 'auth.remember', $event->remember ? 'true' : 'false');
        $manager->addSpanTag($span, 'component', 'auth');
        $manager->addSpanTag($span, 'span.kind', 'server');
    }

    /**
     * Add authentication transaction context
     */
    private function addAuthTransactionContext(OctaneApmManager $manager, string $action, array $credentials = [], $user = null): void
    {
        $manager->addCustomTag('auth.action', $action);
        $manager->addCustomTag('auth.timestamp', now()->toISOString());

        if ($user) {
            $manager->addCustomTag('auth.user_id', (string)$user->getKey());
            $manager->addCustomTag('user.id', (string)$user->getKey());
        }

        $manager->addCustomTag('security.ip_address', Request::ip());
        $manager->addCustomTag('security.user_agent_hash', hash('sha256', Request::userAgent() ?? ''));

        if (!empty($credentials)) {
            $manager->addCustomTag('auth.credentials_provided', json_encode(array_keys($credentials)));
        }
    }

    /**
     * Handle successful authentication event
     */
    public function handleAuthenticated(Authenticated $event): void
    {
        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            $span = $manager->createSpan(
                'User Authenticated',
                'auth',
                'success',
                'security',
            );

            if ($span) {
                $this->setAuthSuccessContext($span, $event);
                $this->addAuthSuccessTags($manager, $span, $event);
                $span->end();
            }

            // Add transaction-level context
            $this->addAuthTransactionContext($manager, 'authenticated', [], $event->user);

        } catch (\Throwable $e) {
            $this->logger?->error('Failed to track authentication success', [
                'exception' => $e->getMessage(),
                'guard'     => $event->guard,
                'user_id'   => $event->user?->getKey(),
            ]);
        }
    }

    /**
     * Set authentication success context
     */
    private function setAuthSuccessContext($span, Authenticated $event): void
    {
        $spanContext = $span->context();

        // Core auth context
        $spanContext->setLabel('auth.guard', $event->guard);
        $spanContext->setLabel('auth.action', 'authenticated');
        $spanContext->setLabel('auth.user_id', (string)$event->user?->getKey());
        $spanContext->setLabel('auth.user_type', get_class($event->user));

        // User context
        $this->setUserContext($spanContext, $event->user);

        // Request context
        $this->setRequestContext($spanContext);

        $span->setOutcome('success');
    }

    /**
     * Set user context
     */
    private function setUserContext(SpanContextInterface $spanContext, $user): void
    {
        if (!$user) return;

        $spanContext->setLabel('user.id', (string)$user->getKey());
        $spanContext->setLabel('user.type', get_class($user));

        if (method_exists($user, 'getEmail')) {
            $spanContext->setLabel('user.email', $this->hashEmail($user->getEmail()));
        }

        if (method_exists($user, 'getRoles')) {
            $spanContext->setLabel('user.roles', json_encode($user->getRoles()->pluck('name')->toArray()));
        }

        // User metadata
        $spanContext->setLabel('user.created_at', $user->created_at?->toISOString());
        $spanContext->setLabel('user.is_new', $user->created_at?->isToday() ? 'true' : 'false');
    }

    /**
     * Hash email for privacy
     */
    private function hashEmail(string $email): string
    {
        return hash('sha256', strtolower($email));
    }

    /**
     * Add authentication success tags
     */
    private function addAuthSuccessTags(OctaneApmManager $manager, $span, Authenticated $event): void
    {
        $manager->addSpanTag($span, 'auth.guard', $event->guard);
        $manager->addSpanTag($span, 'auth.action', 'authenticated');
        $manager->addSpanTag($span, 'auth.user_id', (string)$event->user->getKey());
        $manager->addSpanTag($span, 'component', 'auth');
        $manager->addSpanTag($span, 'span.kind', 'server');
        $manager->addSpanTag($span, 'success', 'true');
    }

    /**
     * Handle authentication failure event
     */
    public function handleFailed(Failed $event): void
    {
        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            $span = $manager->createSpan(
                'Authentication Failed',
                'auth',
                'failure',
                'security',
            );

            if ($span) {
                $this->setAuthFailureContext($span, $event);
                $this->addAuthFailureTags($manager, $span, $event);
                $span->setOutcome('failure');
                $span->end();
            }

            // Add transaction-level context for security monitoring
            $this->addAuthTransactionContext($manager, 'failed', $event->credentials);

            // Record as security event
            $this->recordSecurityEvent($manager, 'auth_failure', [
                'guard'          => $event->guard,
                'credentials'    => array_keys($event->credentials),
                'user_attempted' => $event->user?->getKey(),
                'ip_address'     => Request::ip(),
                'user_agent'     => Request::userAgent(),
            ]);

        } catch (\Throwable $e) {
            $this->logger?->error('Failed to track auth failure', [
                'exception'            => $e->getMessage(),
                'guard'                => $event->guard,
                'credentials_provided' => array_keys($event->credentials),
            ]);
        }
    }

    /**
     * Set authentication failure context
     */
    private function setAuthFailureContext($span, Failed $event): void
    {
        $spanContext = $span->context();

        // Core auth context
        $spanContext->setLabel('auth.guard', $event->guard);
        $spanContext->setLabel('auth.action', 'failed');
        $spanContext->setLabel('auth.credentials_provided', json_encode(array_keys($event->credentials)));
        $spanContext->setLabel('auth.user_attempted', $event->user ? (string)$event->user->getKey() : 'unknown');

        // Security context for failed attempts
        $this->setSecurityContext($spanContext);
        $this->setThreatContext($spanContext);

        // Request context
        $this->setRequestContext($spanContext);
    }

    /**
     * Set threat context
     */
    private function setThreatContext(SpanContextInterface $spanContext): void
    {
        $ip = Request::ip();

        $spanContext->setLabel('threat.ip_reputation', $this->getIpReputation($ip));
        $spanContext->setLabel('threat.failed_attempts_count', $this->getFailedAttemptsCount($ip));
        $spanContext->setLabel('threat.risk_score', $this->calculateRiskScore($ip));
    }

    /**
     * Get IP reputation
     */
    private function getIpReputation(string $ip): string
    {
        // Implement IP reputation checking
        return 'unknown';
    }

    /**
     * Get failed attempts count
     */
    private function getFailedAttemptsCount(string $ip): string
    {
        // Implement failed attempts counting
        return '0';
    }

    /**
     * Calculate risk score
     */
    private function calculateRiskScore(string $ip): string
    {
        // Implement risk score calculation
        return 'low';
    }

    /**
     * Add authentication failure tags
     */
    private function addAuthFailureTags(OctaneApmManager $manager, $span, Failed $event): void
    {
        $manager->addSpanTag($span, 'auth.guard', $event->guard);
        $manager->addSpanTag($span, 'auth.action', 'failed');
        $manager->addSpanTag($span, 'component', 'auth');
        $manager->addSpanTag($span, 'span.kind', 'server');
        $manager->addSpanTag($span, 'error', 'true');
        $manager->addSpanTag($span, 'security.event', 'auth_failure');
    }

    /**
     * Record security event
     */
    private function recordSecurityEvent(OctaneApmManager $manager, string $eventType, array $context): void
    {
        $manager->addCustomTag('security.event.type', $eventType);
        $manager->addCustomTag('security.event.timestamp', now()->toISOString());
        $manager->addCustomTag('security.event.context', json_encode($context));
        $manager->addCustomTag('security.event.severity', $this->getEventSeverity($eventType));
    }

    /**
     * Get event severity
     */
    private function getEventSeverity(string $eventType): string
    {
        return match ($eventType) {
            'auth_failure' => 'medium',
            'account_lockout' => 'high',
            'password_reset' => 'low',
            default => 'low'
        };
    }

    /**
     * Handle successful login event
     */
    public function handleLogin(Login $event): void
    {
        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            $span = $manager->createSpan(
                'User Login',
                'auth',
                'login',
                'security',
            );

            if ($span) {
                $this->setLoginContext($span, $event);
                $this->addLoginTags($manager, $span, $event);
                $span->end();
            }

            // Add user context to transaction
            $this->addUserContextToTransaction($manager, $event->user);

            // Record login metrics
            $this->recordLoginMetrics($manager, $event);

        } catch (\Throwable $e) {
            $this->logger?->error('Failed to track login', [
                'exception' => $e->getMessage(),
                'guard'     => $event->guard,
                'user_id'   => $event->user?->getKey(),
            ]);
        }
    }

    /**
     * Set login context
     */
    private function setLoginContext($span, Login $event): void
    {
        $spanContext = $span->context();

        // Core auth context
        $spanContext->setLabel('auth.guard', $event->guard);
        $spanContext->setLabel('auth.action', 'login');
        $spanContext->setLabel('auth.user_id', (string)$event->user->getKey());
        $spanContext->setLabel('auth.remember', $event->remember ? 'true' : 'false');

        // User context
        $this->setUserContext($spanContext, $event->user);

        // Session context
        $this->setSessionContext($spanContext);

        // Request context
        $this->setRequestContext($spanContext);

        $span->setOutcome('success');
    }

    /**
     * Set session context
     */
    private function setSessionContext(SpanContextInterface $spanContext): void
    {
        $spanContext->setLabel('session.id', session()->getId());
        $spanContext->setLabel('session.lifetime', (string)config('session.lifetime'));
        $spanContext->setLabel('session.driver', config('session.driver'));
    }

    /**
     * Add login tags
     */
    private function addLoginTags(OctaneApmManager $manager, $span, Login $event): void
    {
        $manager->addSpanTag($span, 'auth.guard', $event->guard);
        $manager->addSpanTag($span, 'auth.action', 'login');
        $manager->addSpanTag($span, 'auth.user_id', (string)$event->user->getKey());
        $manager->addSpanTag($span, 'component', 'auth');
        $manager->addSpanTag($span, 'span.kind', 'server');
        $manager->addSpanTag($span, 'business.event', 'user_login');
    }

    /**
     * Add user context to transaction
     */
    private function addUserContextToTransaction(OctaneApmManager $manager, $user): void
    {
        if (!$user) return;

        $manager->addCustomTag('user.id', (string)$user->getKey());
        $manager->addCustomTag('user.type', get_class($user));

        if (method_exists($user, 'getEmail')) {
            $manager->addCustomTag('user.email_hash', $this->hashEmail($user->getEmail()));
        }

        if (method_exists($user, 'getRoles')) {
            $manager->addCustomTag('user.roles', json_encode($user->getRoles()->pluck('name')->toArray()));
        }
    }

    /**
     * Record login metrics
     */
    private function recordLoginMetrics(OctaneApmManager $manager, Login $event): void
    {
        $manager->addCustomTag('metrics.login.guard', $event->guard);
        $manager->addCustomTag('metrics.login.remember', $event->remember ? 'true' : 'false');
        $manager->addCustomTag('metrics.login.timestamp', now()->toISOString());
        $manager->addCustomTag('metrics.login.day_of_week', now()->dayOfWeek);
        $manager->addCustomTag('metrics.login.hour_of_day', now()->hour);
    }

    /**
     * Handle logout event
     */
    public function handleLogout(Logout $event): void
    {
        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            $span = $manager->createSpan(
                'User Logout',
                'auth',
                'logout',
                'security',
            );

            if ($span) {
                $this->setLogoutContext($span, $event);
                $this->addLogoutTags($manager, $span, $event);
                $span->end();
            }

            // Record logout metrics
            $this->recordLogoutMetrics($manager, $event);

        } catch (\Throwable $e) {
            $this->logger?->error('Failed to track logout', [
                'exception' => $e->getMessage(),
                'guard'     => $event->guard,
                'user_id'   => $event->user?->getKey(),
            ]);
        }
    }

    /**
     * Set logout context
     */
    private function setLogoutContext(SpanInterface $span, Logout $event): void
    {
        $spanContext = $span->context();

        // Core auth context
        $spanContext->setLabel('auth.guard', $event->guard);
        $spanContext->setLabel('auth.action', 'logout');
        $spanContext->setLabel('auth.user_id', (string)$event->user->getKey());

        // User context
        $this->setUserContext($spanContext, $event->user);

        // Session context
        $this->setSessionContext($spanContext);

        $span->setOutcome('success');
    }

    /**
     * Add logout tags
     */
    private function addLogoutTags(OctaneApmManager $manager, $span, Logout $event): void
    {
        $manager->addSpanTag($span, 'auth.guard', $event->guard);
        $manager->addSpanTag($span, 'auth.action', 'logout');
        $manager->addSpanTag($span, 'auth.user_id', (string)$event->user->getKey());
        $manager->addSpanTag($span, 'component', 'auth');
        $manager->addSpanTag($span, 'span.kind', 'server');
    }

    /**
     * Record logout metrics
     */
    private function recordLogoutMetrics(OctaneApmManager $manager, Logout $event): void
    {
        $manager->addCustomTag('metrics.logout.guard', $event->guard);
        $manager->addCustomTag('metrics.logout.timestamp', now()->toISOString());
    }

    /**
     * Handle lockout event
     */
    public function handleLockout(Lockout $event): void
    {
        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            $span = $manager->createSpan(
                'Account Lockout',
                'auth',
                'lockout',
                'security',
            );

            if ($span) {
                $this->setLockoutContext($span, $event);
                $this->addLockoutTags($manager, $span, $event);
                $span->setOutcome('failure');
                $span->end();
            }

            // Record as critical security event
            $this->recordSecurityEvent($manager, 'account_lockout', [
                'ip_address'  => Request::ip(),
                'user_agent'  => Request::userAgent(),
                'request_uri' => Request::getRequestUri(),
                'lockout_key' => $this->extractLockoutKey($event),
            ]);

        } catch (\Throwable $e) {
            $this->logger?->error('Failed to track lockout', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Set lockout context
     */
    private function setLockoutContext(SpanInterface $span, Lockout $event): void
    {
        $spanContext = $span->context();

        // Core auth context
        $spanContext->setLabel('auth.action', 'lockout');
        $spanContext->setLabel('auth.lockout_key', $this->extractLockoutKey($event));

        // Security context
        $this->setSecurityContext($spanContext);
        $this->setThreatContext($spanContext);

        // Request context
        $this->setRequestContext($spanContext);
    }

    /**
     * Extract lockout key from event
     */
    private function extractLockoutKey(Lockout $event): string
    {
        // Try to extract from event properties or request
        return Request::ip() . '|' . Request::userAgent();
    }

    /**
     * Add lockout tags
     */
    private function addLockoutTags(OctaneApmManager $manager, $span, Lockout $event): void
    {
        $manager->addSpanTag($span, 'auth.action', 'lockout');
        $manager->addSpanTag($span, 'component', 'auth');
        $manager->addSpanTag($span, 'span.kind', 'server');
        $manager->addSpanTag($span, 'security.event', 'account_lockout');
        $manager->addSpanTag($span, 'threat.level', 'high');
    }

    /**
     * Handle user registration event
     */
    public function handleRegistered(Registered $event): void
    {
        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            $span = $manager->createSpan(
                'User Registration',
                'auth',
                'registration',
                'business',
            );

            if ($span) {
                $this->setRegistrationContext($span, $event);
                $this->addRegistrationTags($manager, $span, $event);
                $span->end();
            }

            // Record business metrics
            $this->recordRegistrationMetrics($manager, $event);

        } catch (\Throwable $e) {
            $this->logger?->error('Failed to track registration', [
                'exception' => $e->getMessage(),
                'user_id'   => $event->user?->getKey(),
            ]);
        }
    }

    /**
     * Set registration context
     */
    private function setRegistrationContext(SpanInterface $span, Registered $event): void
    {
        $spanContext = $span->context();

        // Core auth context
        $spanContext->setLabel('auth.action', 'registration');
        $spanContext->setLabel('auth.user_id', (string)$event->user->getKey());

        // User context
        $this->setUserContext($spanContext, $event->user);

        // Business context
        $spanContext->setLabel('business.event', 'user_acquisition');
        $spanContext->setLabel('business.funnel_stage', 'registration');

        $span->setOutcome('success');
    }

    // Helper methods

    /**
     * Add registration tags
     */
    private function addRegistrationTags(OctaneApmManager $manager, $span, Registered $event): void
    {
        $manager->addSpanTag($span, 'auth.action', 'registration');
        $manager->addSpanTag($span, 'auth.user_id', (string)$event->user->getKey());
        $manager->addSpanTag($span, 'component', 'auth');
        $manager->addSpanTag($span, 'span.kind', 'server');
        $manager->addSpanTag($span, 'business.event', 'user_acquisition');
    }

    /**
     * Record registration metrics
     */
    private function recordRegistrationMetrics(OctaneApmManager $manager, Registered $event): void
    {
        $manager->addCustomTag('metrics.registration.timestamp', now()->toISOString());
        $manager->addCustomTag('metrics.registration.day_of_week', now()->dayOfWeek);
        $manager->addCustomTag('metrics.registration.hour_of_day', now()->hour);
        $manager->addCustomTag('business.conversion.registration', 'true');
    }

    /**
     * Handle email verification event
     */
    public function handleVerified(Verified $event): void
    {
        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            $span = $manager->createSpan(
                'Email Verified',
                'auth',
                'verification',
                'business',
            );

            if ($span) {
                $this->setVerificationContext($span, $event);
                $this->addVerificationTags($manager, $span, $event);
                $span->end();
            }

        } catch (\Throwable $e) {
            $this->logger?->error('Failed to track email verification', [
                'exception' => $e->getMessage(),
                'user_id'   => $event->user?->getKey(),
            ]);
        }
    }

    /**
     * Set verification context
     */
    private function setVerificationContext(SpanInterface $span, Verified $event): void
    {
        $spanContext = $span->context();

        // Core auth context
        $spanContext->setLabel('auth.action', 'verification');
        $spanContext->setLabel('auth.user_id', (string)$event->user->getKey());

        // User context
        $this->setUserContext($spanContext, $event->user);

        // Business context
        $spanContext->setLabel('business.event', 'user_activation');
        $spanContext->setLabel('business.funnel_stage', 'verification');

        $span->setOutcome('success');
    }

    /**
     * Add verification tags
     */
    private function addVerificationTags(OctaneApmManager $manager, $span, Verified $event): void
    {
        $manager->addSpanTag($span, 'auth.action', 'verification');
        $manager->addSpanTag($span, 'auth.user_id', (string)$event->user->getKey());
        $manager->addSpanTag($span, 'component', 'auth');
        $manager->addSpanTag($span, 'span.kind', 'server');
        $manager->addSpanTag($span, 'business.event', 'user_activation');
    }

    /**
     * Handle password reset event
     */
    public function handlePasswordReset(PasswordReset $event): void
    {
        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            $span = $manager->createSpan(
                'Password Reset',
                'auth',
                'password_reset',
                'security',
            );

            if ($span) {
                $this->setPasswordResetContext($span, $event);
                $this->addPasswordResetTags($manager, $span, $event);
                $span->end();
            }

            // Record as security event
            $this->recordSecurityEvent($manager, 'password_reset', [
                'user_id'    => $event->user?->getKey(),
                'ip_address' => Request::ip(),
                'user_agent' => Request::userAgent(),
            ]);

        } catch (\Throwable $e) {
            $this->logger?->error('Failed to track password reset', [
                'exception' => $e->getMessage(),
                'user_id'   => $event->user?->getKey(),
            ]);
        }
    }

    /**
     * Set password reset context
     */
    private function setPasswordResetContext(SpanInterface $span, PasswordReset $event): void
    {
        $spanContext = $span->context();

        // Core auth context
        $spanContext->setLabel('auth.action', 'password_reset');
        $spanContext->setLabel('auth.user_id', (string)$event->user->getKey());

        // User context
        $this->setUserContext($spanContext, $event->user);

        // Security context
        $this->setSecurityContext($spanContext);

        $span->setOutcome('success');
    }

    /**
     * Add password reset tags
     */
    private function addPasswordResetTags(OctaneApmManager $manager, $span, PasswordReset $event): void
    {
        $manager->addSpanTag($span, 'auth.action', 'password_reset');
        $manager->addSpanTag($span, 'auth.user_id', (string)$event->user->getKey());
        $manager->addSpanTag($span, 'component', 'auth');
        $manager->addSpanTag($span, 'span.kind', 'server');
        $manager->addSpanTag($span, 'security.event', 'password_reset');
    }
}