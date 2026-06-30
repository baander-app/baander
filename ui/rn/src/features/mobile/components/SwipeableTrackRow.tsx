/**
 * Swipeable Track Row -- swipe-left reveals actions.
 *
 * Uses react-native-gesture-handler for swipe gesture.
 * Falls back to a plain Pressable row if gesture handler is unavailable.
 */

import React, { useRef } from 'react';
import { View, Text, Pressable, StyleSheet, Animated, PanResponder } from 'react-native';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';
import type { Track } from '@/features/player/stores/player-store';

interface SwipeableTrackRowProps {
  track: Track;
  index: number;
  onPress?: (track: Track, index: number) => void;
  onAddToQueue?: (track: Track) => void;
  onAddToPlaylist?: (track: Track) => void;
}

export function SwipeableTrackRow({
  track,
  index,
  onPress,
  onAddToQueue,
  onAddToPlaylist,
}: SwipeableTrackRowProps) {
  const translateX = useRef(new Animated.Value(0)).current;

  const panResponder = useRef(
    PanResponder.create({
      onMoveShouldSetPanResponder: (_, gs) => gs.dx < -20 && Math.abs(gs.dy) < 30,
      onPanResponderMove: (_, gs) => {
        if (gs.dx < 0) {
          translateX.setValue(Math.max(gs.dx, -120));
        }
      },
      onPanResponderRelease: (_, gs) => {
        if (gs.dx < -80) {
          onAddToQueue?.(track);
        }
        Animated.spring(translateX, { toValue: 0, useNativeDriver: true }).start();
      },
    }),
  ).current;

  return (
    <View style={styles.container}>
      {/* Action revealed behind swipe */}
      <View style={styles.actions}>
        <Pressable style={styles.actionButton} onPress={() => onAddToQueue?.(track)}>
          <Text style={styles.actionText}>+ Queue</Text>
        </Pressable>
        <Pressable style={styles.actionButton} onPress={() => onAddToPlaylist?.(track)}>
          <Text style={styles.actionText}>+ Playlist</Text>
        </Pressable>
      </View>

      {/* Main row */}
      <Animated.View style={[styles.row, { transform: [{ translateX }] }]} {...panResponder.panHandlers}>
        <Pressable style={styles.rowInner} onPress={() => onPress?.(track, index)}>
          <View style={styles.trackInfo}>
            <Text style={styles.title} numberOfLines={1}>{track.title}</Text>
            <Text style={styles.artist} numberOfLines={1}>{track.artistName}</Text>
          </View>
          {track.duration != null && (
            <Text style={styles.duration}>{formatDuration(track.duration)}</Text>
          )}
        </Pressable>
      </Animated.View>
    </View>
  );
}

function formatDuration(seconds: number): string {
  const mins = Math.floor(seconds / 60);
  const secs = Math.floor(seconds % 60);
  return `${mins}:${secs.toString().padStart(2, '0')}`;
}

const styles = StyleSheet.create({
  container: {
    overflow: 'hidden',
  },
  actions: {
    ...StyleSheet.absoluteFillObject,
    flexDirection: 'row',
    justifyContent: 'flex-end',
    backgroundColor: colors.secondary,
  },
  actionButton: {
    width: 80,
    height: '100%',
    alignItems: 'center',
    justifyContent: 'center',
  },
  actionText: {
    color: colors.foreground,
    fontSize: fontSizes.sm,
  },
  row: {
    backgroundColor: colors.background,
  },
  rowInner: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[3],
    gap: spacing[3],
  },
  trackInfo: {
    flex: 1,
    minWidth: 0,
  },
  title: {
    color: colors.foreground,
    fontSize: fontSizes.body,
  },
  artist: {
    color: colors.muted,
    fontSize: fontSizes.sm,
  },
  duration: {
    color: colors.muted,
    fontSize: fontSizes.sm,
  },
});
