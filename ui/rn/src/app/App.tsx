/**
 * Baander RN App Entry Point.
 *
 * Routes to the correct UI shell based on platform:
 * - Desktop (macOS/Windows): DesktopNavigator (three-panel)
 * - Mobile (iOS/Android): MobileNavigator (tabs + mini-player)
 * - TV (Apple TV/Android TV): TVNavigator (D-pad rows)
 */

import React from 'react';
import { Platform } from 'react-native';
import { NavigationContainer } from '@react-navigation/native';
import { DesktopNavigator } from './navigation/desktop-navigator';
import { MobileNavigator } from './navigation/mobile-navigator';
import { TVNavigator } from '@/features/tv/navigation/TVNavigator';

const isTV = Platform.isTV ?? false;
const isDesktop = Platform.OS === 'macos' || Platform.OS === 'windows';

function AppShell() {
  if (isTV) {
    return <TVNavigator />;
  }
  if (isDesktop) {
    return <DesktopNavigator />;
  }
  return <MobileNavigator />;
}

export default function App() {
  return (
    <NavigationContainer>
      <AppShell />
    </NavigationContainer>
  );
}
