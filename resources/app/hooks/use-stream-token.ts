import { useEffect, useState } from 'react';
import { NewAccessTokenResource } from '@/api-client/requests';
import { isTokenExpired, Token } from '@/services/auth/token';
import { refreshStreamToken } from '@/services/auth/stream-token.ts';
import { useSelector } from 'react-redux';
import { selectIsAuthenticated } from '@/store/users/auth-slice.ts';

export function useStreamToken() {
  const [token, setToken] = useState<NewAccessTokenResource | undefined>(Token.getStreamToken());
  const isAuthenticated = useSelector(selectIsAuthenticated);

  useEffect(() => {
    const refresh = () => {
      if (!isAuthenticated) {
        return;
      }

      if (!token || isTokenExpired(token.expiresAt)) {
        refreshStreamToken()
          .then(t => {
            setToken(t);
            Token.setStreamToken(t);
          });
      }
    }

    refresh();
    let timerId = setInterval(() => {
      refresh();
    }, 30_000);

    return () => clearInterval(timerId);
  }, [token, token?.expiresAt, isAuthenticated]);

  const authenticateUrl = (url: string) => {
    if (isAuthenticated && token) {
      return `${url}?_token=${token.token}`;
    } else {
      console.warn('Did not authenticate url');
      return url;
    }
  }

  return {
    authenticateUrl,
    streamToken: token?.token,
  };
}