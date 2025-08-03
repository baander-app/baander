import React, { createContext, useContext, useEffect, useState } from 'react';
import { Token } from '@/services/auth/token.ts';
import { login as loginService, logout as logoutService } from '@/services/auth/login.service.ts';
import { tokenBindingService } from '@/services/auth/token-binding.service';

interface AuthContextType {
  isAuthenticated: boolean;
  isLoading: boolean;
  login: (credentials: { email: string; password: string }) => Promise<void>;
  logout: () => Promise<void>;
  sessionId: string | null;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [isLoading, setIsLoading] = useState(true);
  const [sessionId, setSessionId] = useState<string | null>(null);

  const isAuthenticated = !!Token.get();

  useEffect(() => {
    // Initialize auth state on mount
    const initializeAuth = () => {
      try {
        const token = Token.get();
        const storedSessionId = tokenBindingService.getSessionId();

        if (token && storedSessionId) {
          setSessionId(storedSessionId);
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
      setSessionId(null);
      setIsLoading(false);
    }
  };

  const contextValue: AuthContextType = {
    isAuthenticated,
    isLoading,
    login,
    logout,
    sessionId,
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