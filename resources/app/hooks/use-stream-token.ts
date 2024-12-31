import { useEffect, useState } from 'react';
import { NewAccessTokenResource } from '@/api-client/requests';
import { isTokenExpired, Token } from '@/services/auth/token';
import { refreshStreamToken } from '@/services/auth/stream-token.ts';
import { useAppSelector } from '@/store/hooks.ts';
import { selectIsAuthenticated } from '@/store/users/auth-slice.ts';

export function useStreamToken() {
  const isAuthenticated = useAppSelector(selectIsAuthenticated);
  const [token, setToken] = useState<NewAccessTokenResource | undefined>(Token.getStreamToken());

  useEffect(() => {
    const refresh = () => {
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
  }, [token, token?.expiresAt]);

  return {
    streamToken: token?.token,
  };
}