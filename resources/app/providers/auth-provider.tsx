import React, { createContext, useContext, useEffect, useState, useCallback, useRef } from 'react';
import { NewAccessTokenResource } from '@/libs/api-client/gen/models';
import { Token } from '@/services/auth/token.ts';
import { login as loginService, logout as logoutService } from '@/services/auth/auth.service.ts';
import { tokenBindingService } from '@/services/auth/token-binding.service';
import { refreshToken } from '@/services/auth/refresh-token.service.ts';
import { eventBridge } from '@/services/event-bridge/bridge';

interface AuthContextType {
  isAuthenticated: boolean;
  isLoading: boolean;
  login: (credentials: { email: string; password: string }) => Promise<void>;
  logout: () => Promise<void>;
  sessionId: string | null;
  streamToken?: NewAccessTokenResource;
  authenticateStreamUrl: (url: string) => string;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [isLoading, setIsLoading] = useState(true);
  const [sessionId, setSessionId] = useState<string | null>(null);
  const [streamToken, setStreamToken] = useState<NewAccessTokenResource>();
  const refreshIntervalRef = useRef<NodeJS.Timeout | null>(null);
  const isRefreshingRef = useRef<boolean>(false);

  const isAuthenticated = !!Token.get() && !isLoading;

  // Initialize auth state from storage
  useEffect(() => {
    initializeAuthState();
  }, []);

  // Listen to auth events for state synchronization
  useEffect(() => {
    const unsubscribeLogin = eventBridge.on('auth:login', (data) => {
      setSessionId(data.sessionId || null);
    });

    const unsubscribeLogout = eventBridge.on('auth:logout', () => {
      setSessionId(null);
      setStreamToken(undefined);
      clearRefreshInterval();
    });

    return () => {
      unsubscribeLogin();
      unsubscribeLogout();
    };
  }, []);

  // Setup stream token refresh when authenticated
  useEffect(() => {
    if (isAuthenticated) {
      startStreamTokenRefresh();
    } else {
      clearRefreshInterval();
    }

    return clearRefreshInterval;
  }, [isAuthenticated]);

  const initializeAuthState = useCallback(() => {
    try {
      const authToken = Token.get();
      const storedSessionId = tokenBindingService.getSessionId();
      const storedStreamToken = Token.getStreamToken();

      if (authToken && storedSessionId) {
        setSessionId(storedSessionId);
      }

      if (storedStreamToken && !Token.isExpired(storedStreamToken.expiresAt)) {
        setStreamToken(storedStreamToken);
      }
    } catch (error) {
      console.warn('Failed to initialize auth state:', error);
      clearAuthState();
    } finally {
      setIsLoading(false);
    }
  }, []);

  const startStreamTokenRefresh = useCallback(() => {
    // Initial refresh
    refreshStreamTokenIfNeeded();

    // Set up interval for periodic refresh
    refreshIntervalRef.current = setInterval(refreshStreamTokenIfNeeded, 30_000);
  }, []);

  const refreshStreamTokenIfNeeded = useCallback(async () => {
    if (!isAuthenticated || isRefreshingRef.current) {
      return;
    }

    const currentStreamToken = Token.getStreamToken();
    const needsRefresh = !currentStreamToken || Token.isExpired(currentStreamToken.expiresAt);

    if (needsRefresh) {
      isRefreshingRef.current = true;
      try {
        await refreshToken('stream');
        const newStreamToken = Token.getStreamToken();
        setStreamToken(newStreamToken);
      } catch (error) {
        console.error('Failed to refresh stream token:', error);
        // Don't clear main auth on stream token failure
      } finally {
        isRefreshingRef.current = false;
      }
    }
  }, [isAuthenticated]);

  const clearRefreshInterval = useCallback(() => {
    if (refreshIntervalRef.current) {
      clearInterval(refreshIntervalRef.current);
      refreshIntervalRef.current = null;
    }
    isRefreshingRef.current = false;
  }, []);

  const clearAuthState = useCallback(() => {
    Token.clear();
    tokenBindingService.clear();
    setSessionId(null);
    setStreamToken(undefined);
    clearRefreshInterval();
  }, []);

  const authenticateStreamUrl = useCallback((url: string) => {
    if (!isAuthenticated || !streamToken?.token) {
      console.warn('Stream token not available for URL authentication');
      return url;
    }

    const separator = url.includes('?') ? '&' : '?';
    return `${url}${separator}_token=${streamToken.token}`;
  }, [isAuthenticated, streamToken?.token]);

  const login = useCallback(async (credentials: { email: string; password: string }) => {
    try {
      setIsLoading(true);
      await loginService(credentials);
      // State will be updated via event bridge listener
    } catch (error) {
      clearAuthState();
      throw error;
    } finally {
      setIsLoading(false);
    }
  }, []);

  const logout = useCallback(async () => {
    try {
      setIsLoading(true);
      await logoutService();
      // State will be cleared via event bridge listener
    } catch (error) {
      console.warn('Logout failed:', error);
      // Clear state anyway
      clearAuthState();
    } finally {
      setIsLoading(false);
    }
  }, []);

  const contextValue: AuthContextType = {
    isAuthenticated,
    isLoading,
    login,
    logout,
    sessionId,
    streamToken,
    authenticateStreamUrl,
  };

  return (
    <AuthContext.Provider value={contextValue}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (context === undefined) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
}