/**
 * TVPlaybackControls -- playback controls for now-playing overlay.
 *
 * Previous, play/pause, next buttons with TVFocusable.
 */

import React from 'react';
import { View, StyleSheet } from 'react-native';
import { TVFocusable } from './TVFocusable';
import { usePlayerStore } from '@/features/player/stores/player-store';
import { tvSpacing, tvSizes } from '../theme/tv-tokens';

export interface TVPlaybackControlsProps {
  onDismiss?: () => void;
}

export function TVPlaybackControls({ onDismiss }: TVPlaybackControlsProps) {
  const { isPlaying, setIsPlaying, playPrevious, playNext } = usePlayerStore(
    useShallow((s) => ({
      isPlaying: s.isPlaying,
      setIsPlaying: s.setIsPlaying,
      playPrevious: s.playPrevious,
      playNext: s.playNext,
    })),
  );

  return (
    <View style={styles.container}>
      {/* Previous */}
      <TVFocusable onPress={playPrevious} style={styles.button}>
        <control-text style={styles.buttonText}>⏮</control-text>
      </TVFocusable>

      {/* Play/Pause */}
      <TVFocusable
        onPress={() => setIsPlaying(!isPlaying)}
        style={styles.primaryButton}
      >
        <control-text style={styles.primaryButtonText}>
          {isPlaying ? '⏸' : '▶'}
        </control-text>
      </TVFocusable>

      {/* Next */}
      <TVFocusable onPress={playNext} style={styles.button}>
        <control-text style={styles.buttonText}>⏭</control-text>
      </TVFocusable>

      {/* Dismiss */}
      {onDismiss && (
        <TVFocusable onPress={onDismiss} style={styles.dismissButton}>
          <control-text style={styles.buttonText}>✕</control-text>
        </TVFocusable>
      )}
    </View>
  );
}

import { useShallow } from 'zustand/react/shallow';

const styles = StyleSheet.create({
  container: {
    flexDirection: 'row',
    justifyContent: 'center',
    alignItems: 'center',
    gap: tvSpacing.gap_lg,
  },
  button: {
    width: 64,
    height: 64,
    justifyContent: 'center',
    alignItems: 'center',
  },
  primaryButton: {
    width: 80,
    height: 80,
    justifyContent: 'center',
    alignItems: 'center',
  },
  dismissButton: {
    width: 64,
    height: 64,
    justifyContent: 'center',
    alignItems: 'center',
    marginLeft: tvSpacing.gap_lg,
  },
  buttonText: {
    fontSize: 32,
  },
  primaryButtonText: {
    fontSize: 40,
  },
});
