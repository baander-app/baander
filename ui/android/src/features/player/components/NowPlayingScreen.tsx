/**
 * NowPlayingScreen -- full-screen now playing view.
 *
 * Large album art, track title + artist, progress bar,
 * play/pause/prev/next buttons, shuffle + repeat toggles, queue button.
 */

import React from 'react';
import { View, Text, Pressable, StyleSheet } from 'react-native';
import { usePlayerStore, RepeatMode } from '@/features/player/stores/player-store';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

interface NowPlayingScreenProps {
  onClose: () => void;
  onQueuePress: () => void;
}

/** Format seconds to m:ss. */
function formatTime(seconds: number): string {
  const mins = Math.floor(seconds / 60);
  const secs = Math.floor(seconds % 60);
  return `${mins}:${secs.toString().padStart(2, '0')}`;
}

export function NowPlayingScreen({ onClose, onQueuePress }: NowPlayingScreenProps) {
  const currentTrack = usePlayerStore((s) => s.currentTrack);
  const isPlaying = usePlayerStore((s) => s.isPlaying);
  const currentTime = usePlayerStore((s) => s.currentTime);
  const duration = usePlayerStore((s) => s.duration);
  const shuffle = usePlayerStore((s) => s.shuffle);
  const repeat = usePlayerStore((s) => s.repeat);

  const setIsPlaying = usePlayerStore((s) => s.setIsPlaying);
  const playNext = usePlayerStore((s) => s.playNext);
  const playPrevious = usePlayerStore((s) => s.playPrevious);
  const toggleShuffle = usePlayerStore((s) => s.toggleShuffle);
  const cycleRepeat = usePlayerStore((s) => s.cycleRepeat);

  if (!currentTrack) return null;

  const progress = duration > 0 ? currentTime / duration : 0;

  return (
    <View style={styles.container}>
      {/* Close button */}
      <Pressable style={styles.closeButton} onPress={onClose} hitSlop={12}>
        <Text style={styles.closeText}>{'\u2715'}</Text>
      </Pressable>

      {/* Album art */}
      <View style={styles.artContainer}>
        <View style={styles.albumArt}>
          <Text style={styles.albumIcon}>{'\u266A'}</Text>
        </View>
      </View>

      {/* Track info */}
      <View style={styles.trackInfo}>
        <Text style={styles.trackTitle} numberOfLines={1}>{currentTrack.title}</Text>
        <Text style={styles.trackArtist} numberOfLines={1}>
          {currentTrack.artistName ?? 'Unknown'}
        </Text>
      </View>

      {/* Progress bar */}
      <View style={styles.progressContainer}>
        <View style={styles.progressBar}>
          <View style={[styles.progressFill, { width: `${progress * 100}%` }]} />
        </View>
        <View style={styles.progressTimes}>
          <Text style={styles.timeText}>{formatTime(currentTime)}</Text>
          <Text style={styles.timeText}>{formatTime(duration)}</Text>
        </View>
      </View>

      {/* Transport controls */}
      <View style={styles.controls}>
        <Pressable
          style={[styles.toggleButton, shuffle && styles.toggleActive]}
          onPress={toggleShuffle}
        >
          <Text style={styles.toggleText}>{'\u21C4'}</Text>
        </Pressable>

        <Pressable style={styles.transportButton} onPress={playPrevious}>
          <Text style={styles.transportText}>{'\u23EE'}</Text>
        </Pressable>

        <Pressable
          style={styles.playButton}
          onPress={() => setIsPlaying(!isPlaying)}
        >
          <Text style={styles.playText}>{isPlaying ? '\u23F8' : '\u25B6'}</Text>
        </Pressable>

        <Pressable style={styles.transportButton} onPress={playNext}>
          <Text style={styles.transportText}>{'\u23ED'}</Text>
        </Pressable>

        <Pressable
          style={[styles.toggleButton, repeat !== RepeatMode.Off && styles.toggleActive]}
          onPress={cycleRepeat}
        >
          <Text style={styles.toggleText}>
            {repeat === RepeatMode.One ? '\u21BB1' : '\u21BB'}
          </Text>
        </Pressable>
      </View>

      {/* Queue button */}
      <Pressable style={styles.queueButton} onPress={onQueuePress}>
        <Text style={styles.queueText}>Queue</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.background,
    paddingTop: 60,
    paddingHorizontal: spacing[6],
  },
  closeButton: {
    position: 'absolute',
    top: 16,
    left: 16,
    width: 40,
    height: 40,
    alignItems: 'center',
    justifyContent: 'center',
    zIndex: 10,
  },
  closeText: {
    color: colors.muted,
    fontSize: fontSizes['2xl'],
  },
  artContainer: {
    alignItems: 'center',
    marginTop: spacing[8],
    marginBottom: spacing[8],
  },
  albumArt: {
    width: 280,
    height: 280,
    borderRadius: radii.lg,
    backgroundColor: colors.card,
    alignItems: 'center',
    justifyContent: 'center',
  },
  albumIcon: {
    color: colors.muted,
    fontSize: 64,
  },
  trackInfo: {
    alignItems: 'center',
    marginBottom: spacing[6],
  },
  trackTitle: {
    color: colors.foreground,
    fontSize: fontSizes['2xl'],
    fontWeight: '700',
    textAlign: 'center',
  },
  trackArtist: {
    color: colors.muted,
    fontSize: fontSizes.body,
    marginTop: spacing[1],
  },
  progressContainer: {
    marginBottom: spacing[6],
  },
  progressBar: {
    height: 4,
    backgroundColor: colors.border,
    borderRadius: 2,
    overflow: 'hidden',
  },
  progressFill: {
    height: '100%',
    backgroundColor: colors.primary,
    borderRadius: 2,
  },
  progressTimes: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginTop: spacing[2],
  },
  timeText: {
    color: colors.muted,
    fontSize: fontSizes.xs,
  },
  controls: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing[6],
    marginBottom: spacing[6],
  },
  playButton: {
    width: 64,
    height: 64,
    borderRadius: 32,
    backgroundColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
  },
  playText: {
    color: colors.foreground,
    fontSize: 28,
  },
  transportButton: {
    width: 48,
    height: 48,
    alignItems: 'center',
    justifyContent: 'center',
  },
  transportText: {
    color: colors.foreground,
    fontSize: 24,
  },
  toggleButton: {
    width: 40,
    height: 40,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 20,
  },
  toggleActive: {
    backgroundColor: colors.primary + '33',
  },
  toggleText: {
    color: colors.muted,
    fontSize: fontSizes.body,
  },
  queueButton: {
    alignSelf: 'center',
    paddingHorizontal: spacing[6],
    paddingVertical: spacing[3],
    borderRadius: radii.full,
    backgroundColor: colors.card,
  },
  queueText: {
    color: colors.foreground,
    fontSize: fontSizes.body,
    fontWeight: '500',
  },
});
