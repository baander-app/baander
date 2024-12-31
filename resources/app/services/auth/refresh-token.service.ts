import { Token } from '@/services/auth/token.ts';
import { AuthService, OpenAPI } from '@/api-client/requests';

export async function refreshToken(type: 'access' | 'stream') {
  const token = Token.get();

  if (token?.refreshToken.token) {
    OpenAPI.TOKEN = token.refreshToken.token;
  } else {
    throw new Error('Refresh token not found');
  }

  if (type === 'access') {
    const accessToken = await AuthService.authRefreshToken();

    Token.set({
      accessToken: accessToken.accessToken,
      refreshToken: token.refreshToken,
    });

    OpenAPI.TOKEN = accessToken.accessToken.token;

    return;
  }

  if (type === 'stream') {
    const streamToken = await AuthService.authStreamToken();

    Token.setStreamToken(streamToken.streamToken);

    return;
  }
}