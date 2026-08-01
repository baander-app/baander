/**
 * Mini-player -- compact bar above tab bar showing current track.
 *
 * Tap to expand to full-screen MobileNowPlaying.
 */

import React from 'react';
import { View, Text, Pressable, StyleSheet } from 'react-native';
import { usePlayerStore } from '@/features/player/stores/player-store';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, sizes, fontSizes } from '@/shared/theme/tokens';

interface MiniPlayerProps {
  onPress: () => void;
}

export function MiniPlayer({ onPress }: MiniPlayerProps) {
  const currentTrack = usePlayerStore((s) => s.currentTrack);
  const isPlaying = usePlayerStore((s) => s.isPlaying);

  if (!currentTrack) return null;

  return (
    <Pressable style={styles.container} onPress={onPress}>
      {/* Track info */}
      <View style={styles.info}>
        <Text style={styles.title} numberOfLines={1}>{currentTrack.title}</Text>
        <Text style={styles.artist} numberOfLines={1}>{currentTrack.artistName}</Text>
      </View>

      {/* Play/pause indicator */}
      <View style={styles.indicator}>
        <Text style={styles.indicatorText}>{isPlaying ? '||' : '>'}</Text>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  container: {
    flexDirection: 'row',
    alignItems: 'center',
    height: sizes.mobileNowPlayingBarHeight,
    backgroundColor: colors.card,
    borderTopWidth: 1,
    borderTopColor: colors.border,
    paddingHorizontal: spacing[4],
  },
  info: {
    flex: 1,
    minWidth: 0,
  },
  title: {
    color: colors.foreground,
    fontSize: fontSizes.body,
    fontWeight: '500',
  },
  artist: {
    color: colors.muted,
    fontSize: fontSizes.sm,
  },
  indicator: {
    width: 32,
    height: 32,
    borderRadius: 16,
    backgroundColor: colors.secondary,
    alignItems: 'center',
    justifyContent: 'center',
  },
  indicatorText: {
    color: colors.foreground,
    fontSize: 12,
  },
});
