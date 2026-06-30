/**
 * Desktop Now Playing Bar -- fixed bottom bar with transport controls.
 *
 * Shows current track info, play/pause, next/prev, volume.
 * Matches the web NowPlayingBar layout.
 */

import React from 'react';
import { View, Text, Pressable, StyleSheet } from 'react-native';
import { usePlayerStore } from '@/features/player/stores/player-store';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, sizes, fontSizes } from '@/shared/theme/tokens';

function formatTime(seconds: number): string {
  if (!Number.isFinite(seconds) || seconds < 0) return '0:00';
  const mins = Math.floor(seconds / 60);
  const secs = Math.floor(seconds % 60);
  return `${mins}:${secs.toString().padStart(2, '0')}`;
}

export function DesktopNowPlayingBar() {
  const currentTrack = usePlayerStore((s) => s.currentTrack);
  const isPlaying = usePlayerStore((s) => s.isPlaying);
  const setIsPlaying = usePlayerStore((s) => s.setIsPlaying);
  const playNext = usePlayerStore((s) => s.playNext);
  const playPrevious = usePlayerStore((s) => s.playPrevious);
  const currentTime = usePlayerStore((s) => s.currentTime);
  const duration = usePlayerStore((s) => s.duration);

  if (!currentTrack) return null;

  const progress = duration > 0 ? currentTime / duration : 0;

  return (
    <View style={styles.container}>
      {/* Progress bar */}
      <View style={styles.progressTrack}>
        <View style={[styles.progressFill, { flex: progress }]} />
        <View style={{ flex: 1 - progress }} />
      </View>

      <View style={styles.row}>
        {/* Track info */}
        <View style={styles.trackInfo}>
          <Text style={styles.trackTitle} numberOfLines={1}>{currentTrack.title}</Text>
          <Text style={styles.trackArtist} numberOfLines={1}>
            {[currentTrack.artistName, currentTrack.albumName].filter(Boolean).join(' · ')}
          </Text>
        </View>

        {/* Transport controls */}
        <View style={styles.controls}>
          <Pressable onPress={playPrevious} style={styles.controlButton}>
            <Text style={styles.controlText}>|&lt;</Text>
          </Pressable>
          <Pressable onPress={() => setIsPlaying(!isPlaying)} style={styles.playButton}>
            <Text style={styles.playText}>{isPlaying ? '||' : '>'}</Text>
          </Pressable>
          <Pressable onPress={playNext} style={styles.controlButton}>
            <Text style={styles.controlText}>|&gt;</Text>
          </Pressable>
        </View>

        {/* Time */}
        <View style={styles.timeInfo}>
          <Text style={styles.timeText}>{formatTime(currentTime)} / {formatTime(duration)}</Text>
        </View>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    backgroundColor: colors.background,
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  progressTrack: {
    flexDirection: 'row',
    height: 2,
    backgroundColor: colors.border,
  },
  progressFill: {
    backgroundColor: colors.foreground,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[2],
    gap: spacing[4],
  },
  trackInfo: {
    flex: 1,
    minWidth: 0,
  },
  trackTitle: {
    color: colors.foreground,
    fontSize: fontSizes.body,
    fontWeight: '500',
  },
  trackArtist: {
    color: colors.muted,
    fontSize: fontSizes.sm,
  },
  controls: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[2],
  },
  controlButton: {
    width: 32,
    height: 32,
    borderRadius: 16,
    alignItems: 'center',
    justifyContent: 'center',
  },
  controlText: {
    color: colors.foreground,
    fontSize: 14,
  },
  playButton: {
    width: 36,
    height: 36,
    borderRadius: 18,
    backgroundColor: colors.foreground,
    alignItems: 'center',
    justifyContent: 'center',
  },
  playText: {
    color: colors.background,
    fontSize: 14,
    fontWeight: '600',
  },
  timeInfo: {
    minWidth: 80,
    alignItems: 'flex-end',
  },
  timeText: {
    color: colors.muted,
    fontSize: fontSizes.sm,
  },
});
