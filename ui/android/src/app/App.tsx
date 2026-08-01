/**
 * Baander Android App Entry Point.
 *
 * Simple: initCrypto → AuthStack.
 * No TV/Desktop branches needed — this is phone/tablet only.
 */

import React from 'react';
import { NavigationContainer } from '@react-navigation/native';
import { AuthStack } from './navigation/auth-stack';

export default function App() {
  return (
    <NavigationContainer>
      <AuthStack />
    </NavigationContainer>
  );
}
