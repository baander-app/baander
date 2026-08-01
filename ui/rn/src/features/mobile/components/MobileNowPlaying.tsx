/**
 * Mobile Now-Playing -- full-screen overlay with large artwork + transport controls.
 *
 * Dismissed by swipe-down or back button.
 */

import React from 'react';
import { View, Text, Pressable, StyleSheet, useWindowDimensions } from 'react-native';
import { usePlayerStore } from '@/features/player/stores/player-store';
import { RepeatMode } from '@/features/player/stores/player-store';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, sizes, fontSizes } from '@/shared/theme/tokens';

function formatTime(seconds: number): string {
  if (!Number.isFinite(seconds) || seconds < 0) return '0:00';
  const mins = Math.floor(seconds / 60);
  const secs = Math.floor(seconds % 60);
  return `${mins}:${secs.toString().padStart(2, '0')}`;
}

interface MobileNowPlayingProps {
  onDismiss: () => void;
}

export function MobileNowPlaying({ onDismiss }: MobileNowPlayingProps) {
  const { width } = useWindowDimensions();
  const currentTrack = usePlayerStore((s) => s.currentTrack);
  const isPlaying = usePlayerStore((s) => s.isPlaying);
  const setIsPlaying = usePlayerStore((s) => s.setIsPlaying);
  const playNext = usePlayerStore((s) => s.playNext);
  const playPrevious = usePlayerStore((s) => s.playPrevious);
  const currentTime = usePlayerStore((s) => s.currentTime);
  const duration = usePlayerStore((s) => s.duration);
  const shuffle = usePlayerStore((s) => s.shuffle);
  const toggleShuffle = usePlayerStore((s) => s.toggleShuffle);
  const repeat = usePlayerStore((s) => s.repeat);
  const cycleRepeat = usePlayerStore((s) => s.cycleRepeat);

  if (!currentTrack) return null;

  const progress = duration > 0 ? currentTime / duration : 0;
  const artworkSize = Math.min(width - 48, 400);

  return (
    <View style={styles.container}>
      {/* Dismiss */}
      <Pressable style={styles.dismissButton} onPress={onDismiss}>
        <Text style={styles.dismissText}>Close</Text>
      </Pressable>

      {/* Artwork placeholder */}
      <View style={[styles.artwork, { width: artworkSize, height: artworkSize }]}>
        <Text style={styles.artworkText}>?</Text>
      </View>

      {/* Track info */}
      <Text style={styles.title} numberOfLines={1}>{currentTrack.title}</Text>
      <Text style={styles.artist} numberOfLines={1}>{currentTrack.artistName}</Text>

      {/* Progress */}
      <View style={styles.progressRow}>
        <Text style={styles.timeText}>{formatTime(currentTime)}</Text>
        <View style={styles.progressTrack}>
          <View style={[styles.progressFill, { flex: progress }]} />
          <View style={{ flex: Math.max(0, 1 - progress) }} />
        </View>
        <Text style={styles.timeText}>{formatTime(duration)}</Text>
      </View>

      {/* Transport controls */}
      <View style={styles.controls}>
        <Pressable onPress={toggleShuffle} style={styles.controlButton}>
          <Text style={[styles.controlText, shuffle && styles.controlActive]}>S</Text>
        </Pressable>
        <Pressable onPress={playPrevious} style={styles.controlButton}>
          <Text style={styles.controlText}>|&lt;</Text>
        </Pressable>
        <Pressable onPress={() => setIsPlaying(!isPlaying)} style={styles.playButton}>
          <Text style={styles.playText}>{isPlaying ? '||' : '>'}</Text>
        </Pressable>
        <Pressable onPress={playNext} style={styles.controlButton}>
          <Text style={styles.controlText}>|&gt;</Text>
        </Pressable>
        <Pressable onPress={cycleRepeat} style={styles.controlButton}>
          <Text style={[styles.controlText, repeat !== RepeatMode.Off && styles.controlActive]}>
            R{repeat === RepeatMode.One ? '1' : ''}
          </Text>
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    ...StyleSheet.absoluteFillObject,
    backgroundColor: colors.background,
    padding: spacing[6],
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing[3],
  },
  dismissButton: {
    position: 'absolute',
    top: 16,
    left: 16,
    padding: spacing[2],
  },
  dismissText: {
    color: colors.muted,
    fontSize: fontSizes.body,
  },
  artwork: {
    backgroundColor: colors.card,
    borderRadius: radii.xl,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing[4],
  },
  artworkText: {
    color: colors.muted,
    fontSize: 48,
  },
  title: {
    color: colors.foreground,
    fontSize: fontSizes.xl,
    fontWeight: '600',
    textAlign: 'center',
  },
  artist: {
    color: colors.muted,
    fontSize: fontSizes.body,
    textAlign: 'center',
  },
  progressRow: {
    flexDirection: 'row',
    alignItems: 'center',
    width: '100%',
    gap: spacing[2],
  },
  progressTrack: {
    flex: 1,
    flexDirection: 'row',
    height: 4,
    backgroundColor: colors.border,
    borderRadius: 2,
  },
  progressFill: {
    backgroundColor: colors.foreground,
    borderRadius: 2,
  },
  timeText: {
    color: colors.muted,
    fontSize: fontSizes.sm,
    width: 40,
  },
  controls: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[4],
    marginTop: spacing[2],
  },
  controlButton: {
    width: 40,
    height: 40,
    alignItems: 'center',
    justifyContent: 'center',
  },
  controlText: {
    color: colors.muted,
    fontSize: 16,
  },
  controlActive: {
    color: colors.primary,
  },
  playButton: {
    width: 56,
    height: 56,
    borderRadius: 28,
    backgroundColor: colors.foreground,
    alignItems: 'center',
    justifyContent: 'center',
  },
  playText: {
    color: colors.background,
    fontSize: 20,
    fontWeight: '600',
  },
});
