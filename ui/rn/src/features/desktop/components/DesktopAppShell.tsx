/**
 * Desktop AppShell -- three-panel layout matching web app.
 *
 * Sidebar (224px) | Content (flex) | Context Panel (collapsible)
 * + DesktopNowPlayingBar fixed at bottom
 */

import React from 'react';
import { View, StyleSheet } from 'react-native';
import { Sidebar } from './Sidebar';
import { ContextPanel } from './ContextPanel';
import { DesktopNowPlayingBar } from './DesktopNowPlayingBar';
import { SidebarEditor } from './SidebarEditor';
import { colors } from '@/shared/theme/colors';
import { sizes, spacing } from '@/shared/theme/tokens';

interface DesktopAppShellProps {
  children: React.ReactNode;
}

export function DesktopAppShell({ children }: DesktopAppShellProps) {
  return (
    <View style={styles.container}>
      <View style={styles.mainRow}>
        <Sidebar />
        <View style={styles.content}>
          {children}
        </View>
        <ContextPanel />
      </View>
      <DesktopNowPlayingBar />
      <SidebarEditor />
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.background,
  },
  mainRow: {
    flex: 1,
    flexDirection: 'row',
  },
  content: {
    flex: 1,
  },
});
