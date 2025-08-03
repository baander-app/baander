
import React, { createContext, useContext, useEffect, useState, useCallback, useRef } from 'react';
import { NewAccessTokenResource } from '@/libs/api-client/gen/models';
import { Token } from '@/services/auth/token.ts';
import { login as loginService, logout as logoutService } from '@/services/auth/auth.service.ts';
import { tokenBindingService } from '@/services/auth/token-binding.service';
import { isTokenExpired } from '@/services/auth/token.ts';
import { refreshStreamToken } from '@/services/auth/stream-token.ts';

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
  const refreshTimeoutRef = useRef<NodeJS.Timeout | null>(null);
  const isRefreshingRef = useRef<boolean>(false);

  const isAuthenticated = !!Token.get() && !isLoading;

  useEffect(() => {
    // Initialize auth state on mount
    const initializeAuth = () => {
      try {
        const token = Token.get();
        const storedSessionId = tokenBindingService.getSessionId();
        const storedStreamToken = Token.getStreamToken();

        if (token && storedSessionId) {
          setSessionId(storedSessionId);
        }

        if (storedStreamToken) {
          setStreamToken(storedStreamToken);
        }
      } catch (error) {
        console.warn('Failed to initialize auth:', error);
        Token.clear();
        tokenBindingService.clear();
      } finally {
        setIsLoading(false);
      }
    };

    initializeAuth();
  }, []);

  // Stream token refresh logic
  const refreshStreamTokenIfNeeded = useCallback(async () => {
    if (!isAuthenticated || isRefreshingRef.current) {
      return;
    }

    if (!streamToken?.token || isTokenExpired(streamToken.expiresAt)) {
      isRefreshingRef.current = true;
      try {
        const newStreamToken = await refreshStreamToken();
        setStreamToken(newStreamToken);
        Token.setStreamToken(newStreamToken);
      } catch (error) {
        console.error('Failed to refresh stream token:', error);
        // Don't clear auth on stream token failure
      } finally {
        isRefreshingRef.current = false;
      }
    }
  }, [isAuthenticated, streamToken?.token, streamToken?.expiresAt]);

  // Setup stream token refresh interval
  useEffect(() => {
    if (!isAuthenticated) {
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
  }, [isAuthenticated, refreshStreamTokenIfNeeded]);

  const authenticateStreamUrl = useCallback((url: string) => {
    if (isAuthenticated && streamToken?.token) {
      return `${url}?_token=${streamToken.token}`;
    }
    console.warn('Stream token not available for URL authentication');
    return url;
  }, [isAuthenticated, streamToken?.token]);

  const login = async (credentials: { email: string; password: string }) => {
    try {
      setIsLoading(true);
      const response = await loginService(credentials);

      if (response.sessionId) {
        setSessionId(response.sessionId);
      }
    } catch (error) {
      setSessionId(null);
      throw error;
    } finally {
      setIsLoading(false);
    }
  };

  const logout = async () => {
    try {
      setIsLoading(true);
      await logoutService();
    } catch (error) {
      console.warn('Logout failed:', error);
    } finally {
      // Clear stream token refresh interval
      if (refreshTimeoutRef.current) {
        clearInterval(refreshTimeoutRef.current);
        refreshTimeoutRef.current = null;
      }
      isRefreshingRef.current = false;

      setSessionId(null);
      setStreamToken(undefined);
      setIsLoading(false);
    }
  };

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