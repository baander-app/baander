import { Token } from '@/services/auth/token.ts';
import { NotificationFacade } from '@/modules/notifications/notification-facade.ts';
import { authRefreshToken, authStreamToken } from '@/libs/api-client/gen/endpoints/auth/auth.ts';
import { tokenBindingService } from '@/services/auth/token-binding.service.ts';
import { HeaderExt } from '@/models/header-ext.ts';

type TokenType = 'access' | 'stream';

export async function refreshToken(type: TokenType) {
  const authTokens = Token.get();

  if (!authTokens?.refreshToken) {
    throw new Error('Refresh token not found');
  }

  const sessionId = tokenBindingService.getSessionId();
  const headers = {
    'Authorization': `Bearer ${authTokens.refreshToken.token}`,
    ...(sessionId && { [HeaderExt.X_BAANDER_SESSION_ID]: sessionId }),
  };

  try {
    if (type === 'access') {
      await refreshAccessToken(authTokens, headers);
    } else {
      await refreshStreamToken(headers);
    }
  } catch (error) {
    handleRefreshError(type, error);
    throw error;
  }
}

async function refreshAccessToken(authTokens: any, headers: Record<string, string>) {
  const response = await authRefreshToken({
    headers,
    _skipAuth: false,
  });

  Token.set({
    accessToken: response.accessToken,
    refreshToken: authTokens.refreshToken, // Keep existing refresh token
    sessionId: authTokens.sessionId,
  });
}

async function refreshStreamToken(headers: Record<string, string>) {
  const response = await authStreamToken({
    headers,
    _skipAuth: false,
  });

  Token.setStreamToken(response.streamToken);
}

function handleRefreshError(type: TokenType, error: any) {
  NotificationFacade.create({
    type: 'error',
    title: 'Authentication error',
    message: `Failed to refresh ${type} token\n\n${error.message}`,
    toast: true,
  });

  // Clear tokens if refresh fails
  Token.clear();
  tokenBindingService.clear();
}