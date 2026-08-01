/**
 * TVAdminRoute -- route guard for admin-only access.
 *
 * Checks auth-store for admin role.
 * Non-admin users see dedicated access denied screen before redirect to home.
 */

import React, { useEffect } from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { useAuthStore } from '@/features/auth/stores/auth-store';
import { TVAdminAccessDeniedPage } from '../pages/TVAdminAccessDeniedPage';
import { tvColors, tvFontSizes, tvSpacing } from '../theme/tv-tokens';

export interface TVAdminRouteProps {
  children: React.ReactNode;
}

/**
 * Check if user has admin role.
 */
function hasAdminRole(roles: string[] | null | undefined): boolean {
  if (!roles) return false;
  return roles.includes('ROLE_ADMIN') || roles.includes('ROLE_SUPER_ADMIN');
}

export function TVAdminRoute({ children }: TVAdminRouteProps) {
  const navigation = useNavigation();
  const { user, isAuthenticated } = useAuthStore();

  // Not authenticated -- redirect to login (placeholder behavior)
  if (!isAuthenticated) {
    return <TVAdminAccessDeniedPage reason="not_authenticated" />;
  }

  // Not admin -- show access denied
  if (!hasAdminRole(user?.roles)) {
    return <TVAdminAccessDeniedPage reason="not_admin" />;
  }

  // Admin -- render children
  return <>{children}</>;
}
