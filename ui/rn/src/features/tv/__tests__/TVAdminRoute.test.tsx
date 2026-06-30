/**
 * TVAdminRoute component tests.
 */

import React from 'react';
import { render, screen } from '@testing-library/react-native';
import { NavigationContainer } from '@react-navigation/native';
import { TVAdminRoute } from '../components/TVAdminRoute';

// Mock auth store
jest.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: () => ({
    user: {
      uuid: '123',
      email: 'admin@example.com',
      publicId: 'admin',
      name: 'Admin User',
      roles: ['ROLE_ADMIN'],
    },
    isAuthenticated: true,
  }),
}));

describe('TVAdminRoute', () => {
  it('renders children when user is authenticated admin', () => {
    const { useAuthStore } = require('@/features/auth/stores/auth-store');
    useAuthStore.mockReturnValue({
      user: { roles: ['ROLE_ADMIN'] },
      isAuthenticated: true,
    });

    render(
      <NavigationContainer>
        <TVAdminRoute>
          <test-child data-testid="admin-content">Admin Content</test-child>
        </TVAdminRoute>
      </NavigationContainer>
    );

    expect(screen.getByTestId('admin-content')).toBeTruthy();
  });

  it('shows access denied when not authenticated', () => {
    const { useAuthStore } = require('@/features/auth/stores/auth-store');
    useAuthStore.mockReturnValue({
      user: null,
      isAuthenticated: false,
    });

    render(
      <NavigationContainer>
        <TVAdminRoute>
          <test-child data-testid="admin-content">Admin Content</test-child>
        </TVAdminRoute>
      </NavigationContainer>
    );

    expect(screen.getByText('Access Denied')).toBeTruthy();
    expect(screen.queryByTestId('admin-content')).toBeNull();
  });

  it('shows access denied when authenticated but not admin', () => {
    const { useAuthStore } = require('@/features/auth/stores/auth-store');
    useAuthStore.mockReturnValue({
      user: { roles: ['ROLE_USER'] },
      isAuthenticated: true,
    });

    render(
      <NavigationContainer>
        <TVAdminRoute>
          <test-child data-testid="admin-content">Admin Content</test-child>
        </TVAdminRoute>
      </NavigationContainer>
    );

    expect(screen.getByText('Access Denied')).toBeTruthy();
    expect(screen.queryByTestId('admin-content')).toBeNull();
  });
});
