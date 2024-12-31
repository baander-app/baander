import { Token } from '@/services/auth/token.ts';
import { AuthService, OpenAPI } from '@/api-client/requests';
import { setAccessToken, setStreamToken } from '@/store/users/auth-slice.ts';
import { store } from '@/store';

export async function refreshToken(type: 'access' | 'stream') {
  const refreshToken = store.getState().auth?.refreshToken;

  if (refreshToken) {
    OpenAPI.TOKEN = refreshToken.token;
  } else {
    throw new Error('Refresh token not found');
  }

  if (type === 'access') {
    const accessToken = await AuthService.authRefreshToken();

    Token.set({
      accessToken: accessToken.accessToken,
      refreshToken: refreshToken,
    });
    store.dispatch(setAccessToken(accessToken.accessToken));

    OpenAPI.TOKEN = accessToken.accessToken.token;

    return;
  }

  if (type === 'stream') {
    const streamToken = await AuthService.authStreamToken();

    Token.setStreamToken(streamToken.streamToken);
    store.dispatch(setStreamToken(streamToken.streamToken));

    return;
  }
}