/**
 * MiniPlayer -- compact bar at bottom showing current track + basic controls.
 *
 * Tap to open NowPlayingScreen. Play/pause + next buttons.
 */

import React from 'react';
import { View, Text, Pressable, StyleSheet } from 'react-native';
import { usePlayerStore } from '@/features/player/stores/player-store';
import { colors } from '@/shared/theme/colors';
import { spacing, sizes, fontSizes } from '@/shared/theme/tokens';

interface MiniPlayerProps {
  onPress: () => void;
}

export function MiniPlayer({ onPress }: MiniPlayerProps) {
  const currentTrack = usePlayerStore((s) => s.currentTrack);
  const isPlaying = usePlayerStore((s) => s.isPlaying);
  const setIsPlaying = usePlayerStore((s) => s.setIsPlaying);
  const playNext = usePlayerStore((s) => s.playNext);

  if (!currentTrack) return null;

  return (
    <Pressable style={styles.container} onPress={onPress}>
      {/* Track info */}
      <View style={styles.info}>
        <Text style={styles.title} numberOfLines={1}>{currentTrack.title}</Text>
        <Text style={styles.artist} numberOfLines={1}>{currentTrack.artistName}</Text>
      </View>

      {/* Play/pause button */}
      <Pressable
        style={styles.controlButton}
        onPress={() => setIsPlaying(!isPlaying)}
        hitSlop={8}
      >
        <Text style={styles.controlText}>{isPlaying ? '\u23F8' : '\u25B6'}</Text>
      </Pressable>

      {/* Next button */}
      <Pressable
        style={styles.controlButton}
        onPress={playNext}
        hitSlop={8}
      >
        <Text style={styles.controlText}>{'\u23ED'}</Text>
      </Pressable>
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
  controlButton: {
    width: 36,
    height: 36,
    borderRadius: 18,
    backgroundColor: colors.secondary,
    alignItems: 'center',
    justifyContent: 'center',
    marginLeft: spacing[2],
  },
  controlText: {
    color: colors.foreground,
    fontSize: 16,
  },
});
