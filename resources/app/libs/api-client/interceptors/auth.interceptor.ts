import { AxiosInstance, AxiosResponse, InternalAxiosRequestConfig } from 'axios';
import { Token } from '@/app/services/auth/token.ts';
import { refreshToken } from '@/app/services/auth/refresh-token.service.ts';
import { tokenBindingService } from '@/app/services/auth/token-binding.service.ts';
import { HeaderExt } from '@/app/models/header-ext.ts';

interface AuthRequest extends InternalAxiosRequestConfig {
  _didRetry?: boolean;
  _skipAuth?: boolean;
}

interface TokenBindingError {
  message: string;
  code: 'TOKEN_BINDING_FAILED';
  reason: string;
  action?: 'reauth' | 'logout' | 'revoke_all_tokens';
}

export function authInterceptor(instance: AxiosInstance) {
  // Add auth headers to requests
  instance.interceptors.request.use(
    (config: AuthRequest) => {
      if (config._skipAuth) return config;

      config.headers = config.headers || {};

      // Add access token if not already present
      if (!config.headers.Authorization) {
        const authToken = Token.get();
        if (authToken?.access_token) {
          config.headers.Authorization = `Bearer ${authToken.access_token}`;
        }
      }

      // Add session ID for server-side validation
      const sessionId = tokenBindingService.getSessionId();
      if (sessionId && !config.headers[HeaderExt.X_BAANDER_SESSION_ID]) {
        config.headers[HeaderExt.X_BAANDER_SESSION_ID] = sessionId;
      }

      return config;
    },
    (error) => Promise.reject(error)
  );

  // Handle auth failures and token refresh
  instance.interceptors.response.use(
    (response: AxiosResponse) => response,
    async (error) => {
      const originalRequest = error.config as AuthRequest;

      if (!error.response || originalRequest._didRetry || originalRequest._skipAuth) {
        return Promise.reject(error);
      }

      const { status, data } = error.response;

      // Handle server-side token binding failures
      if (status === 401 && data?.code === 'TOKEN_BINDING_FAILED') {
        handleTokenBindingFailure(data as TokenBindingError);
        return Promise.reject(error);
      }

      // Handle token expiration with refresh
      if (status === 401 || status === 403) {
        return handleTokenRefresh(originalRequest, instance);
      }

      return Promise.reject(error);
    }
  );

  setupInactivityTimeout();
}

function handleTokenBindingFailure(error: TokenBindingError) {
  console.warn('Server-side token binding failed:', error.reason);

  clearAuthAndRedirect();
  showSecurityNotification(error.reason);
}

async function handleTokenRefresh(originalRequest: AuthRequest, instance: AxiosInstance) {
  const authHeader = originalRequest.headers?.Authorization as string;

  if (!authHeader?.startsWith('Bearer ')) {
    return Promise.reject(new Error('No valid authorization header'));
  }

  // Don't retry refresh requests
  if (isRefreshRequest(originalRequest)) {
    clearAuthAndRedirect();
    return Promise.reject(new Error('Refresh token expired'));
  }

  originalRequest._didRetry = true;

  try {
    await refreshToken();
    updateRequestToken(originalRequest);

    // Update session ID if changed
    const sessionId = tokenBindingService.getSessionId();
    if (sessionId) {
      originalRequest.headers = originalRequest.headers || {};
      originalRequest.headers[HeaderExt.X_BAANDER_SESSION_ID] = sessionId;
    }

    return instance(originalRequest);
  } catch (refreshError) {
    clearAuthAndRedirect();
    showNotification('error', 'Authentication Failed', 'Please log in again.');
    return Promise.reject(refreshError);
  }
}

function isRefreshRequest(request: AuthRequest): boolean {
  return !!(request.url?.includes('/refreshToken') || request.url?.includes('/streamToken'));
}

function updateRequestToken(request: AuthRequest) {
  request.headers = request.headers || {};

  const newTokens = Token.get();
  if (newTokens?.access_token) {
    request.headers.Authorization = `Bearer ${newTokens.access_token}`;
  }
}

function clearAuthAndRedirect() {
  // Token.clear();
  // tokenBindingService.clear();
  //
  // setTimeout(() => {
  //   if (typeof window !== 'undefined') {
  //     window.location.href = '/login';
  //   }
  // }, 100);
}

function showSecurityNotification(reason: string) {
  const notifications = {
    concurrent_ip_usage: { type: 'error', title: 'SECURITY ALERT', message: 'Account used from multiple locations. All sessions terminated.' },
    max_ip_changes_exceeded: { type: 'error', title: 'Security Alert', message: 'Too many location changes detected.' },
    rapid_ip_changes: { type: 'warning', title: 'Suspicious Activity', message: 'Rapid location changes detected.' },
    suspicious_geo_jump: { type: 'warning', title: 'Unusual Location', message: 'Impossible travel detected.' },
    default: { type: 'warning', title: 'Session Expired', message: 'Please log in again.' }
  };

  const notification = notifications[reason as keyof typeof notifications] || notifications.default;
  showNotification(notification.type as any, notification.title, notification.message);
}

function showNotification(type: 'error' | 'warning' | 'info', title: string, message: string) {
  console.warn(`${title}: ${message}`);

  window.dispatchEvent(new CustomEvent('auth-notification', {
    detail: { type, title, message }
  }));
}

function setupInactivityTimeout() {
  if (typeof window === 'undefined') return;

  let activityTimer: NodeJS.Timeout;
  const INACTIVITY_TIMEOUT = 30 * 60 * 1000; // 30 minutes

  const resetTimer = () => {
    clearTimeout(activityTimer);
    activityTimer = setTimeout(() => {
      if (Token.get()) {
        clearAuthAndRedirect();
        showNotification('info', 'Session Timeout', 'Logged out due to inactivity.');
      }
    }, INACTIVITY_TIMEOUT);
  };

  const handleActivity = () => {
    if (Token.get()) resetTimer();
  };

  ['mousedown', 'keydown', 'scroll', 'touchstart'].forEach(event => {
    document.addEventListener(event, handleActivity, true);
  });

  if (Token.get()) resetTimer();
}