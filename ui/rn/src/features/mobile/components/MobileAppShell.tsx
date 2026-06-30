/**
 * Mobile AppShell -- bottom tabs + mini-player.
 *
 * Structure: Content (flex) | MiniPlayer | TabBar
 * No sidebar, no context panel.
 */

import React, { useState } from 'react';
import { View, StyleSheet } from 'react-native';
import { MiniPlayer } from './MiniPlayer';
import { MobileTabBar } from './MobileTabBar';
import { MobileNowPlaying } from './MobileNowPlaying';
import { colors } from '@/shared/theme/colors';

interface MobileAppShellProps {
  children: React.ReactNode;
}

export function MobileAppShell({ children }: MobileAppShellProps) {
  const [nowPlayingExpanded, setNowPlayingExpanded] = useState(false);

  return (
    <View style={styles.container}>
      {/* Main content */}
      <View style={styles.content}>
        {children}
      </View>

      {/* Mini-player (above tab bar) */}
      <MiniPlayer onPress={() => setNowPlayingExpanded(true)} />

      {/* Tab bar */}
      <MobileTabBar />

      {/* Full-screen now-playing overlay */}
      {nowPlayingExpanded && (
        <MobileNowPlaying onDismiss={() => setNowPlayingExpanded(false)} />
      )}
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
});
