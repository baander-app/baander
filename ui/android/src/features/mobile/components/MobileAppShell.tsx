/**
 * Mobile AppShell -- content area + mini-player slot + tab bar.
 *
 * Structure: Content (flex) | MiniPlayer slot | TabBar
 * No sidebar, no context panel.
 */

import React from 'react';
import { View, StyleSheet } from 'react-native';
import { MobileTabBar } from './MobileTabBar';
import { colors } from '@/shared/theme/colors';

interface MobileAppShellProps {
  children: React.ReactNode;
}

export function MobileAppShell({ children }: MobileAppShellProps) {
  return (
    <View style={styles.container}>
      {/* Main content */}
      <View style={styles.content}>
        {children}
      </View>

      {/* Mini-player slot (above tab bar, rendered here for z-ordering) */}
      <View style={styles.miniPlayerSlot} />

      {/* Tab bar */}
      <MobileTabBar />
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.background,
  },
  content: {
    flex: 1,
  },
  miniPlayerSlot: {
    height: 64,
    backgroundColor: colors.card,
    borderTopWidth: 1,
    borderTopColor: colors.border,
    justifyContent: 'center',
    alignItems: 'center',
  },
});
