/**
 * Auth navigation stack.
 *
 * Wraps the main app content in a ProtectedRoute.
 * When not authenticated, shows login/register.
 * When authenticated, renders the provided children (AppShell).
 */

import React from 'react';
import { createStackNavigator } from '@react-navigation/stack';
import { ProtectedRoute } from '@/features/auth/components/ProtectedRoute';
import App from '@/app/App';

const Stack = createStackNavigator();

export function AuthStack() {
  return (
    <Stack.Navigator screenOptions={{ headerShown: false }}>
      <Stack.Screen name="Main">
        {() => (
          <ProtectedRoute>
            <App />
          </ProtectedRoute>
        )}
      </Stack.Screen>
    </Stack.Navigator>
  );
}
