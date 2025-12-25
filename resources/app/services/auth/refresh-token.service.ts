import { Token } from '@/app/services/auth/token.ts';
import { NotificationFacade } from '@/app/modules/notifications/notification-facade.ts';
import { authRefreshToken } from '@/app/libs/api-client/gen/endpoints/auth/auth.ts';
import { tokenBindingService } from '@/app/services/auth/token-binding.service.ts';

export async function refreshToken() {
  const authTokens = Token.get();

  if (!authTokens?.refresh_token) {
    throw new Error('Refresh token not found');
  }

  // const sessionId = tokenBindingService.getSessionId();
  // const headers = {
  //   'Authorization': `Bearer ${authTokens.refresh_token}`,
  //   ...(sessionId && { [HeaderExt.X_BAANDER_SESSION_ID]: sessionId }),
  // };

  try {
    await refreshAccessToken(authTokens);
  } catch (error) {
    handleRefreshError(error);
    throw error;
  }
}

async function refreshAccessToken(authTokens: any) {
  if (authTokens.refresh_token === null) {
    return;
  }

  const response = await authRefreshToken(authTokens);

  Token.set({
    access_token: response.access_token,
    refresh_token: authTokens.refresh_token, // Keep existing refresh token
    session_id: authTokens.session_id,
    expires_in: response.expires_in
  });
}

function handleRefreshError(error: any) {
  NotificationFacade.create({
    type: 'error',
    title: 'Authentication error',
    message: `Failed to refresh token\n\n${error.message}`,
    toast: true,
  });

  // Clear tokens if refresh fails
  Token.clear();
  tokenBindingService.clear();
}