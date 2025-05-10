import { Token } from '@/services/auth/token.ts';
import { AuthService, OpenAPI } from '@/api-client/requests';
import { setAccessToken, setStreamToken } from '@/store/users/auth-slice.ts';
import { store } from '@/store';
import { NotificationFacade } from '@/modules/notifications/notification-facade.ts';

export async function refreshToken(type: 'access' | 'stream') {
  const refreshToken = store.getState().auth?.refreshToken;

  if (refreshToken) {
    OpenAPI.TOKEN = refreshToken.token;
  } else {
    throw new Error('Refresh token not found');
  }

  if (type === 'access') {
    try {
      const accessToken = await AuthService.postApiAuthRefreshToken();
      Token.set({
        accessToken: accessToken.accessToken,
        refreshToken: refreshToken,
      });
      store.dispatch(setAccessToken(accessToken.accessToken));

      OpenAPI.TOKEN = accessToken.accessToken.token;
    } catch (e) {
      NotificationFacade.create({
        type: 'error',
        message: 'Failed to refresh access token',
      });

      throw e;
    }

    return;
  }

  if (type === 'stream') {
    try {
      const streamToken = await AuthService.postApiAuthStreamToken();

      Token.setStreamToken(streamToken.streamToken);
      store.dispatch(setStreamToken(streamToken.streamToken));
    } catch (e) {
      NotificationFacade.create({
        type: 'error',
        message: 'Failed to refresh stream token',
      });

      throw e;
    }

    return;
  }
}