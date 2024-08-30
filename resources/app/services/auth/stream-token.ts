import { NewAccessTokenResource } from '@/api-client/requests';
import { isTokenExpired, Token } from '@/services/auth/token.ts';

export function refreshStreamToken() {
  return new Promise<NewAccessTokenResource>((resolve, reject) => {
    const refreshToken = Token.get()?.refreshToken;

    if (!refreshToken || isTokenExpired(refreshToken.expiresAt)) {
      reject(new Error('Refresh token expired'));
    }

    fetch(route('auth.streamToken'), {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${refreshToken!.token}`,
      },
    }).then(res => res.json())
      .then((res: { streamToken: NewAccessTokenResource }) => {
        resolve(res.streamToken);
      }).catch((e) => {
      reject(e);
    });
  });
}