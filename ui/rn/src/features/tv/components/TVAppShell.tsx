/**
 * TVAppShell - Full-screen shell for Apple TV interface.
 *
 * TV-optimized layout characteristics:
 * - Full-screen content (no sidebar, no context panel, no mini-player bar)
 * - Safe zone compliance (90% of screen to accommodate overscan)
 * - Children rendered as the entire screen
 *
 * The now-playing overlay is a separate modal that appears on top of any screen.
 */

import React from 'react';
import { View, StyleSheet, StatusBar, Platform } from 'react-native';
import { tvColors } from '../theme/tv-tokens';

export interface TVAppShellProps {
  children: React.ReactNode;
}

export function TVAppShell({ children }: TVAppShellProps) {
  return (
    <View style={styles.container}>
      <StatusBar barStyle="light-content" />
      <View style={styles.safeZone}>{children}</View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: tvColors.background,
  },
  safeZone: {
    flex: 1,
    // 90% safe zone for older TVs with overscan
    marginHorizontal: '5%',
    marginVertical: '5%',
  },
});
