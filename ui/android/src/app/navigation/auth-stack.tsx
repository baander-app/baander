/**
 * Auth navigation stack.
 *
 * Wraps the main app content in a ProtectedRoute.
 * When not authenticated, shows the login flow with three auth methods.
 * When authenticated, renders the AppNavigator.
 */

import React from 'react';
import { createStackNavigator } from '@react-navigation/stack';
import { ProtectedRoute } from '@/features/auth/components/ProtectedRoute';
import { AppNavigator } from './app-navigator';

const Stack = createStackNavigator();

export function AuthStack() {
  return (
    <Stack.Navigator screenOptions={{ headerShown: false }}>
      <Stack.Screen name="Main">
        {() => (
          <ProtectedRoute>
            <AppNavigator />
          </ProtectedRoute>
        )}
      </Stack.Screen>
    </Stack.Navigator>
  );
}
