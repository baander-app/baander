import React, { useContext, useEffect, useState, useCallback, useRef } from 'react';
import { NewAccessTokenResource } from '@/libs/api-client/gen/models';
import { noop } from '@/utils/noop.ts';
import { isTokenExpired, Token } from '@/services/auth/token.ts';
import { refreshStreamToken } from '@/services/auth/stream-token.ts';

interface AuthContextType {
  accessToken?: NewAccessTokenResource;
  setAccessToken: (accessToken: NewAccessTokenResource) => void;
  refreshToken?: NewAccessTokenResource;
  setRefreshToken: (refreshToken: NewAccessTokenResource) => void;
  streamToken?: NewAccessTokenResource;
  setStreamToken: (streamToken: NewAccessTokenResource) => void;
  authenticateStreamUrl: (url: string) => string;
  isAuthenticated: boolean;
  logout: () => void;
}

const AuthContext = React.createContext<AuthContextType>({
  accessToken: undefined,
  setAccessToken: noop,
  refreshToken: undefined,
  setRefreshToken: noop,
  streamToken: undefined,
  setStreamToken: noop,
  authenticateStreamUrl: (url: string) => url,
  isAuthenticated: false,
  logout: () => noop(),
});
AuthContext.displayName = 'AuthContext';

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [accessToken, setAccessToken] = useState<NewAccessTokenResource>();
  const [refreshToken, setRefreshToken] = useState<NewAccessTokenResource>();
  const [streamToken, setStreamToken] = useState<NewAccessTokenResource>();
  const [isInitialized, setIsInitialized] = useState(false);
  const refreshTimeoutRef = useRef<NodeJS.Timeout | null>(null);
  const isRefreshingRef = useRef<boolean>(false);

  const isAuthenticated = !!accessToken && isInitialized;

  // Helper function to persist auth tokens to storage
  const persistAuthTokens = useCallback((accessToken: NewAccessTokenResource, refreshToken: NewAccessTokenResource) => {
    Token.set({ accessToken, refreshToken });
  }, []);

  // Centralized token update function to prevent race conditions
  const updateTokens = useCallback((tokens: {
    accessToken?: NewAccessTokenResource;
    refreshToken?: NewAccessTokenResource;
    streamToken?: NewAccessTokenResource;
  }) => {
    const { accessToken: newAccessToken, refreshToken: newRefreshToken, streamToken: newStreamToken } = tokens;

    // Update state
    if (newAccessToken !== undefined) setAccessToken(newAccessToken);
    if (newRefreshToken !== undefined) setRefreshToken(newRefreshToken);
    if (newStreamToken !== undefined) setStreamToken(newStreamToken);

    // Update storage - need to handle individual token updates
    if (newStreamToken) {
      Token.setStreamToken(newStreamToken);
    }
  }, []);

  // Effect to persist auth tokens whenever they change
  useEffect(() => {
    if (accessToken && refreshToken) {
      persistAuthTokens(accessToken, refreshToken);
    }
  }, [accessToken, refreshToken, persistAuthTokens]);

  // Initialize tokens from storage once on mount
  useEffect(() => {
    const initializeAuth = async () => {
      try {
        const authToken = Token.get();
        const storedStreamToken = Token.getStreamToken();

        if (authToken) {
          setAccessToken(authToken.accessToken);
          setRefreshToken(authToken.refreshToken);
        }

        if (storedStreamToken) {
          setStreamToken(storedStreamToken);
        }
      } catch (error) {
        console.error('Failed to initialize auth tokens:', error);
        Token.clear();
      } finally {
        setIsInitialized(true);
      }
    };

    initializeAuth();
  }, []);

  // Stream token refresh logic with better error handling
  const refreshStreamTokenIfNeeded = useCallback(async () => {
    if (!isAuthenticated || isRefreshingRef.current) {
      return;
    }

    if (!streamToken?.token || isTokenExpired(streamToken.expiresAt)) {
      isRefreshingRef.current = true;
      try {
        const newStreamToken = await refreshStreamToken();
        updateTokens({ streamToken: newStreamToken });
      } catch (error) {
        console.error('Failed to refresh stream token:', error);
        // Don't clear auth on stream token failure
      } finally {
        isRefreshingRef.current = false;
      }
    }
  }, [isAuthenticated, streamToken?.token, streamToken?.expiresAt, updateTokens]);

  // Setup stream token refresh interval
  useEffect(() => {
    if (!isAuthenticated || !isInitialized) {
      return;
    }

    refreshStreamTokenIfNeeded();

    refreshTimeoutRef.current = setInterval(refreshStreamTokenIfNeeded, 30_000);

    return () => {
      if (refreshTimeoutRef.current) {
        clearInterval(refreshTimeoutRef.current);
        refreshTimeoutRef.current = null;
      }
    };
  }, [isAuthenticated, isInitialized, refreshStreamTokenIfNeeded]);

  const authenticateStreamUrl = useCallback((url: string) => {
    if (isAuthenticated && streamToken?.token) {
      return `${url}?_token=${streamToken.token}`;
    }
    console.warn('Stream token not available for URL authentication');
    return url;
  }, [isAuthenticated, streamToken?.token]);

  const logout = useCallback(() => {
    if (refreshTimeoutRef.current) {
      clearInterval(refreshTimeoutRef.current);
      refreshTimeoutRef.current = null;
    }
    isRefreshingRef.current = false;

    setAccessToken(undefined);
    setRefreshToken(undefined);
    setStreamToken(undefined);
    Token.clear();
  }, []);

  const contextValue = {
    accessToken,
    setAccessToken: (token: NewAccessTokenResource) => updateTokens({ accessToken: token }),
    refreshToken,
    setRefreshToken: (token: NewAccessTokenResource) => updateTokens({ refreshToken: token }),
    streamToken,
    setStreamToken: (token: NewAccessTokenResource) => updateTokens({ streamToken: token }),
    authenticateStreamUrl,
    isAuthenticated,
    logout,
  };

  return (
    <AuthContext.Provider value={contextValue}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  return useContext(AuthContext);
}