import { AxiosInstance, AxiosResponse, InternalAxiosRequestConfig } from 'axios';
import { Token } from '@/services/auth/token.ts';
import { refreshToken } from '@/services/auth/refresh-token.service.ts';
import { tokenBindingService } from '@/services/auth/token-binding.service.ts';
import { HeaderExt } from '@/models/header-ext.ts';

interface AuthRequest extends InternalAxiosRequestConfig {
  _didRetry?: boolean;
  _skipAuth?: boolean;
}

interface TokenBindingError {
  message: string;
  code: 'TOKEN_BINDING_FAILED';
  reason: 'fingerprint_mismatch' | 'session_mismatch' | 'max_ip_changes_exceeded' | string;
  action?: 'reauth' | 'logout';
}

// Simple notification system that doesn't depend on Redux
function showNotification(type: 'error' | 'warning' | 'info', title: string, message: string) {
  console.warn(`${title}: ${message}`);

  // Try to show browser notification if available
  if ('Notification' in window && Notification.permission === 'granted') {
    new Notification(title, {
      body: message,
      icon: '/favicon.ico',
    });
  }

  // You can also emit a custom event here for your notification system to catch
  window.dispatchEvent(new CustomEvent('auth-notification', {
    detail: { type, title, message }
  }));
}

export function authInterceptor(instance: AxiosInstance) {
  // Request interceptor - add authorization and session headers
  instance.interceptors.request.use(
    async (config: AuthRequest) => {
      // Skip auth for login, register, and other public endpoints
      if (config._skipAuth) {
        return config;
      }

      // Validate client environment before making authenticated requests
      if (!tokenBindingService.validateEnvironment()) {
        console.warn('Client environment validation failed, clearing tokens');
        Token.clear();
        tokenBindingService.clear();
        return Promise.reject(new Error('Environment validation failed'));
      }

      // Add authorization header with Bearer token
      const authToken = Token.get();
      if (authToken?.accessToken?.token) {
        config.headers = config.headers || {};
        config.headers.Authorization = `Bearer ${authToken.accessToken.token}`;
      }

      // Add session ID header for token binding validation
      const sessionId = tokenBindingService.getSessionId();
      if (sessionId) {
        config.headers = config.headers || {};
        config.headers[HeaderExt.X_BAANDER_SESSION_ID] = sessionId;
      }

      return config;
    },
    (error) => Promise.reject(error)
  );

  // Response interceptor - handle authentication failures and token refresh
  instance.interceptors.response.use(
    (response: AxiosResponse) => response,
    async (error) => {
      const originalRequest = error.config as AuthRequest;

      // Skip retry logic for non-HTTP errors, already retried requests, or public endpoints
      if (!error.response || originalRequest._didRetry || originalRequest._skipAuth) {
        return Promise.reject(error);
      }

      const { status, data } = error.response;

      // Handle token binding validation failures
      if (status === 401 && data?.code === 'TOKEN_BINDING_FAILED') {
        return handleTokenBindingFailure(data as TokenBindingError);
      }

      // Handle other authentication failures with token refresh
      if (status === 401 || status === 403) {
        return handleAuthenticationFailure(originalRequest, instance);
      }

      return Promise.reject(error);
    }
  );

  /**
   * Handle token binding validation failures
   */
  function handleTokenBindingFailure(errorData: TokenBindingError) {
    console.warn('Token binding validation failed:', errorData.reason);

    // Clear all authentication data
    Token.clear();
    tokenBindingService.clear();

    // Show appropriate notification based on failure reason
    const { type, title, message } = getTokenBindingNotification(errorData.reason);
    showNotification(type, title, message);

    // Redirect to login page
    redirectToLogin();

    return Promise.reject(new Error(`Token binding failed: ${errorData.reason}`));
  }

  /**
   * Handle general authentication failures with token refresh attempt
   */
  async function handleAuthenticationFailure(originalRequest: AuthRequest, axiosInstance: AxiosInstance) {
    const authHeader = originalRequest.headers?.Authorization as string;

    // Ensure we have a bearer token
    if (!authHeader?.startsWith('Bearer ')) {
      return Promise.reject(new Error('No valid authorization header'));
    }

    // Determine token type (access or stream)
    const requestToken = authHeader.replace('Bearer ', '');
    const tokenType = getTokenType(requestToken);

    if (!tokenType) {
      console.warn('Unknown token type, cannot refresh');
      return Promise.reject(new Error('Unknown token type'));
    }

    // Mark request as retried to prevent infinite loops
    originalRequest._didRetry = true;

    try {
      // Attempt to refresh the token
      await refreshToken(tokenType);

      // Update request with new token
      updateRequestWithNewToken(originalRequest, tokenType);

      // Re-add session ID
      const sessionId = tokenBindingService.getSessionId();
      if (sessionId) {
        originalRequest.headers = originalRequest.headers || {};
        originalRequest.headers[HeaderExt.X_BAANDER_SESSION_ID] = sessionId;
      }

      console.log('Token refreshed successfully, retrying request');
      return axiosInstance(originalRequest);

    } catch (refreshError) {
      console.error('Token refresh failed:', refreshError);

      // Clear authentication data and redirect
      Token.clear();
      tokenBindingService.clear();

      showNotification(
        'error',
        'Authentication Failed',
        'Unable to refresh your session. Please log in again.'
      );

      redirectToLogin();
      return Promise.reject(refreshError);
    }
  }

  /**
   * Get notification configuration for token binding failures
   */
  function getTokenBindingNotification(reason: string) {
    switch (reason) {
      case 'max_ip_changes_exceeded':
        return {
          type: 'error' as const,
          title: 'Security Alert',
          message: 'Too many location changes detected. Please log in again for security.',
        };
      case 'fingerprint_mismatch':
        return {
          type: 'warning' as const,
          title: 'Device Changed',
          message: 'Your device fingerprint has changed. Please log in again.',
        };
      case 'session_mismatch':
        return {
          type: 'warning' as const,
          title: 'Session Invalid',
          message: 'Your session is no longer valid. Please log in again.',
        };
      default:
        return {
          type: 'warning' as const,
          title: 'Session Expired',
          message: 'Please log in again.',
        };
    }
  }

  /**
   * Determine token type from request token
   */
  function getTokenType(requestToken: string): 'access' | 'stream' | undefined {
    const authTokens = Token.get();
    const streamToken = Token.getStreamToken();

    if (authTokens?.accessToken?.token === requestToken) {
      return 'access';
    }

    if (streamToken?.token === requestToken) {
      return 'stream';
    }

    return undefined;
  }

  /**
   * Update request headers with refreshed token
   */
  function updateRequestWithNewToken(request: AuthRequest, tokenType: 'access' | 'stream') {
    request.headers = request.headers || {};

    if (tokenType === 'access') {
      const newTokens = Token.get();
      if (newTokens?.accessToken?.token) {
        request.headers.Authorization = `Bearer ${newTokens.accessToken.token}`;
      }
    } else if (tokenType === 'stream') {
      const newStreamToken = Token.getStreamToken();
      if (newStreamToken?.token) {
        request.headers.Authorization = `Bearer ${newStreamToken.token}`;
      }
    }
  }

  /**
   * Redirect to login page
   */
  function redirectToLogin() {
    if (typeof window !== 'undefined') {
      window.location.href = '/login';
    }
  }

  // Setup security monitoring and automatic cleanup
  setupSecurityMonitoring();
  setupInactivityTimeout();
}

/**
 * Setup security monitoring for environment changes
 */
function setupSecurityMonitoring() {
  if (typeof document === 'undefined') return;

  const handleVisibilityChange = () => {
    // Skip if tab is hidden
    if (document.hidden) return;

    // Validate security when tab becomes visible
    const authToken = Token.get();
    if (authToken && !tokenBindingService.validateEnvironment()) {
      console.warn('Security validation failed on tab focus');

      Token.clear();
      tokenBindingService.clear();

      showNotification(
        'warning',
        'Security Check Failed',
        'Your session has been cleared due to environment changes.'
      );
    }
  };

  document.addEventListener('visibilitychange', handleVisibilityChange);
}

/**
 * Setup automatic logout on prolonged inactivity
 */
function setupInactivityTimeout() {
  if (typeof window === 'undefined') return;

  let activityTimer: NodeJS.Timeout;
  const INACTIVITY_TIMEOUT = 30 * 60 * 1000; // 30 minutes

  const resetActivityTimer = () => {
    clearTimeout(activityTimer);
    activityTimer = setTimeout(() => {
      const authToken = Token.get();
      if (authToken) {
        console.log('Auto-logout: User inactive for 30 minutes');

        Token.clear();
        tokenBindingService.clear();

        showNotification(
          'info',
          'Session Timeout',
          'You have been logged out due to inactivity.'
        );

        window.location.href = '/login';
      }
    }, INACTIVITY_TIMEOUT);
  };

  const handleUserActivity = () => {
    const authToken = Token.get();
    if (authToken) {
      resetActivityTimer();
    }
  };

  // Listen for user activity events
  const activityEvents = ['mousedown', 'keydown', 'scroll', 'touchstart'];
  activityEvents.forEach(event => {
    document.addEventListener(event, handleUserActivity, true);
  });

  // Initialize timer if user is already authenticated
  const authToken = Token.get();
  if (authToken) {
    resetActivityTimer();
  }
}
