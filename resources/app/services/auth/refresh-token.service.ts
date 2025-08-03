import { Token } from '@/services/auth/token.ts';
import { NotificationFacade } from '@/modules/notifications/notification-facade.ts';
import { authRefreshToken, authStreamToken } from '@/libs/api-client/gen/endpoints/auth/auth.ts';
import { useAuth } from '@/providers/auth-provider.tsx';

export async function refreshToken(type: 'access' | 'stream') {
  const { refreshToken, setAccessToken, setStreamToken } = useAuth();

  if (refreshToken) {
  } else {
    throw new Error('Refresh token not found');
  }

  if (type === 'access') {
    try {
      const accessToken = await authRefreshToken();
      Token.set({
        accessToken: accessToken.accessToken,
        refreshToken: refreshToken,
      });
      setAccessToken(accessToken.accessToken);
    } catch (e) {
      NotificationFacade.create({
        type: 'error',
        title: 'Authentication error',
        message: 'Failed to refresh access token',
        toast: true,
      });

      throw e;
    }

    return;
  }

  if (type === 'stream') {
    try {
      const streamToken = await authStreamToken();

      Token.setStreamToken(streamToken.streamToken);
      setStreamToken(streamToken.streamToken);
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