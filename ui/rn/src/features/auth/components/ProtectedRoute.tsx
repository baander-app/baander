/**
 * Protected route guard.
 *
 * Renders children only when authenticated.
 * Shows the login flow when not authenticated.
 */

import React, { useState } from 'react';
import { View } from 'react-native';
import { useAuthStore } from '@/features/auth/stores/auth-store';
import { LoginPage } from '@/features/auth/pages/LoginPage';
import { RegisterPage } from '@/features/auth/pages/RegisterPage';

interface ProtectedRouteProps {
  children: React.ReactNode;
}

export function ProtectedRoute({ children }: ProtectedRouteProps) {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);
  const [showRegister, setShowRegister] = useState(false);

  if (isAuthenticated) {
    return <>{children}</>;
  }

  if (showRegister) {
    return <RegisterPage onSwitchToLogin={() => setShowRegister(false)} />;
  }

  return <LoginPage onSwitchToRegister={() => setShowRegister(true)} />;
}
