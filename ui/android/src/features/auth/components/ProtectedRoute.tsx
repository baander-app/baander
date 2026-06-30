/**
 * Protected route guard.
 *
 * Renders children only when authenticated.
 * Shows the login flow (with three auth method tabs) when not authenticated.
 */

import React from 'react';
import { LoginPage } from '@/features/auth/pages/LoginPage';
import { useAuthStore } from '@/features/auth/stores/auth-store';

interface ProtectedRouteProps {
  children: React.ReactNode;
}

export function ProtectedRoute({ children }: ProtectedRouteProps) {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);

  if (isAuthenticated) {
    return <>{children}</>;
  }

  return <LoginPage />;
}
