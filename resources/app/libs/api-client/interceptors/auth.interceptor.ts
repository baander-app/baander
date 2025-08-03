import { AxiosInstance, InternalAxiosRequestConfig, AxiosResponse } from 'axios';
import { refreshToken } from '@/services/auth/refresh-token.service.ts';
import { Token } from '@/services/auth/token.ts';

interface AuthRequest extends InternalAxiosRequestConfig {
  _didRetry?: boolean;
  _skipAuth?: boolean;
}

export function authInterceptor(instance: AxiosInstance) {
  // Request interceptor - add authorization header
  instance.interceptors.request.use(
    (config: AuthRequest) => {
      // Skip auth for specific requests (like login)
      if (config._skipAuth) {
        return config;
      }

      const authToken = Token.get();
      if (authToken?.accessToken?.token) {
        config.headers.Authorization = `Bearer ${authToken.accessToken.token}`;
      }

      return config;
    },
    (error) => Promise.reject(error)
  );

  // Response interceptor - handle token refresh
  instance.interceptors.response.use(
    (response: AxiosResponse) => response,
    async (error) => {
      const originalRequest = error.config as AuthRequest;

      if (!error.response || originalRequest._didRetry || originalRequest._skipAuth) {
        return Promise.reject(error);
      }

      const { status } = error.response;

      if (status === 401 || status === 403) {
        const authHeader = originalRequest.headers?.Authorization as string;
        if (!authHeader?.startsWith('Bearer ')) {
          return Promise.reject(error);
        }

        const requestToken = authHeader.replace('Bearer ', '');
        const authTokens = Token.get();
        const streamToken = Token.getStreamToken();

        let tokenType: 'access' | 'stream' | undefined;

        if (authTokens?.accessToken?.token === requestToken) {
          tokenType = 'access';
        } else if (streamToken?.token === requestToken) {
          tokenType = 'stream';
        }

        if (!tokenType) {
          return Promise.reject(error);
        }

        originalRequest._didRetry = true;

        try {
          await refreshToken(tokenType);

          // Update the authorization header with the new token
          const newTokens = Token.get();
          if (tokenType === 'access' && newTokens?.accessToken?.token) {
            originalRequest.headers.Authorization = `Bearer ${newTokens.accessToken.token}`;
          } else if (tokenType === 'stream') {
            const newStreamToken = Token.getStreamToken();
            if (newStreamToken?.token) {
              originalRequest.headers.Authorization = `Bearer ${newStreamToken.token}`;
            }
          }

          // Retry the original request
          return instance(originalRequest);
        } catch (refreshError) {
          console.error('Token refresh failed:', refreshError);
          // Clear tokens and redirect to login if needed
          Token.clear();
          return Promise.reject(refreshError);
        }
      }

      return Promise.reject(error);
    }
  );
}