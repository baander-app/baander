import { OpenAPI } from '@/api-client/requests';
import { InternalAxiosRequestConfig } from 'axios';
import { refreshToken } from '@/services/auth/refresh-token.service.ts';
import { Token } from '@/services/auth/token.ts';

export function refreshAccessTokenInterceptor() {
  OpenAPI.interceptors.response.use(
    async (response) => {
      const request = response.config as AccessTokenRequest;
      
      if (response?.status === 401 || response?.status === 403 && !request?._didRetry) {
        console.warn('retrying')

        const requestToken = request.headers['Authorization']?.replace('Bearer ', '');

        const isAccessToken = Token.get()?.accessToken.token === requestToken;
        const isStreamToken = Token.getStreamToken()?.token === requestToken;
        let type: 'access' | 'stream' | undefined = undefined;

        if (isAccessToken) {
          type = 'access';
        } else if (isStreamToken) {
          type = 'stream';
        } else {
          return response;
        }

        request._didRetry = true;
        await refreshToken(type!);
      }

      return response;
    },
  );
}

interface AccessTokenRequest extends InternalAxiosRequestConfig {
  _didRetry?: boolean;
}