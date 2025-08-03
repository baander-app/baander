
import { Token } from '@/services/auth/token.ts';
import { NotificationFacade } from '@/modules/notifications/notification-facade.ts';
import { authRefreshToken, authStreamToken } from '@/libs/api-client/gen/endpoints/auth/auth.ts';
import { tokenBindingService } from '@/services/auth/token-binding.service.ts';
import { HeaderExt } from '@/models/header-ext.ts';

export async function refreshToken(type: 'access' | 'stream') {
  const authTokens = Token.get();

  if (!authTokens?.refreshToken) {
    throw new Error('Refresh token not found');
  }

  if (type === 'access') {
    try {
      // Get session ID for token binding
      const sessionId = tokenBindingService.getSessionId();

      // Call the API with refresh token authorization and session header
      const response = await authRefreshToken({
        headers: {
          'Authorization': `Bearer ${authTokens.refreshToken.token}`,
          ...(sessionId && { [HeaderExt.X_BAANDER_SESSION_ID]: sessionId }),
        },
        _skipAuth: false, // We need the refresh token auth, not access token
      });

      // Update token storage
      Token.set({
        accessToken: response.accessToken,
        refreshToken: authTokens.refreshToken, // Keep existing refresh token
        sessionId: authTokens.sessionId,
      });

      // Note: We can't call useAuth hooks here since this is not a component
      // The auth provider should listen to token changes or we need a different approach

    } catch (e) {
      NotificationFacade.create({
        type: 'error',
        title: 'Authentication error',
        message: 'Failed to refresh access token',
        toast: true,
      });

      // Clear tokens if refresh fails
      Token.clear();
      tokenBindingService.clear();

      throw e;
    }
    return;
  }

  if (type === 'stream') {
    try {
      // Get session ID for token binding
      const sessionId = tokenBindingService.getSessionId();

      // Call the API with refresh token authorization and session header
      const response = await authStreamToken({
        headers: {
          'Authorization': `Bearer ${authTokens.refreshToken.token}`,
          ...(sessionId && { [HeaderExt.X_BAANDER_SESSION_ID]: sessionId }),
        },
        _skipAuth: false, // We need the refresh token auth, not access token
      });

      // Update stream token storage
      Token.setStreamToken(response.streamToken);

    } catch (e) {
      NotificationFacade.create({
        type: 'error',
        title: 'Authentication error',
        message: 'Failed to refresh stream token',
      });

      throw e;
    }
    return;
  }
}