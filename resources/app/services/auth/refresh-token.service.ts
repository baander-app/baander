import { Token } from '@/services/auth/token.ts';
import { AuthService, OpenAPI } from '@/api-client/requests';
import { useAppDispatch, useAppSelector } from '@/store/hooks.ts';
import { selectRefreshToken, setAccessToken, setRefreshToken, setStreamToken } from '@/store/users/auth-slice.ts';

export async function refreshToken(type: 'access' | 'stream') {
  const dispatch = useAppDispatch();
  const refreshToken = useAppSelector(state => state.auth)

  if (refreshToken) {
    OpenAPI.TOKEN = refreshToken.refreshToken.token;
  } else {
    throw new Error('Refresh token not found');
  }

  if (type === 'access') {
    const accessToken = await AuthService.authRefreshToken();

    Token.set({
      accessToken: accessToken.accessToken,
      refreshToken: token.refreshToken,
    });
    dispatch(setAccessToken(accessToken.accessToken));
    dispatch(setRefreshToken(token.refreshToken));

    OpenAPI.TOKEN = accessToken.accessToken.token;

    return;
  }

  if (type === 'stream') {
    const streamToken = await AuthService.authStreamToken();

    Token.setStreamToken(streamToken.streamToken);
    dispatch(setStreamToken(streamToken.streamToken));

    return;
  }
}