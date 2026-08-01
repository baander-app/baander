/**
 * TVNowPlayingOverlay -- full-screen now-playing overlay.
 *
 * Per user decision: overlay stays visible during navigation and animates with page transitions.
 * Shows large album artwork, track info, playback controls.
 * Dismissible with back button or dismiss button.
 */

import React from 'react';
import { Modal, View, Image, Text, StyleSheet } from 'react-native';
import { TVPlaybackControls } from './TVPlaybackControls';
import { tvColors, tvFontSizes, tvSizes, tvSpacing, tvRadii } from '../theme/tv-tokens';
import { useTVNowPlaying } from '../hooks/use-tv-now-playing';

export function TVNowPlayingOverlay() {
  const { isVisible, currentTrack, isPlaying, progress, dismiss } = useTVNowPlaying();

  if (!isVisible || !currentTrack) {
    return null;
  }

  return (
    <Modal
      visible={isVisible}
      transparent={false}
      animationType="fade"
      onRequestClose={dismiss}
    >
      <View style={styles.container}>
        {/* Background artwork (blurred) */}
        <Image
          source={{ uri: currentTrack.coverImageBlurhash ? undefined : 'https://via.placeholder.com/600' }}
          style={styles.backgroundArtwork}
          blurRadius={50}
        />

        {/* Content overlay */}
        <View style={styles.content}>
          {/* Artwork */}
          <Image
            source={{ uri: 'https://via.placeholder.com/400' }}
            style={styles.artwork}
            resizeMode="contain"
          />

          {/* Track info */}
          <View style={styles.trackInfo}>
            <Text style={styles.title}>{currentTrack.title}</Text>
            {currentTrack.artistName && (
              <Text style={styles.artist}>{currentTrack.artistName}</Text>
            )}
            {currentTrack.albumName && (
              <Text style={styles.album}>{currentTrack.albumName}</Text>
            )}
          </View>

          {/* Progress bar */}
          <View style={styles.progressContainer}>
            <View style={styles.progressBar}>
              <View style={[styles.progressFill, { width: `${progress}%` }]} />
            </View>
            <Text style={styles.progressText}>0:00 / 3:45</Text>
          </View>

          {/* Playback controls */}
          <TVPlaybackControls onDismiss={dismiss} />
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: tvColors.background,
  },
  backgroundArtwork: {
    position: 'absolute',
    width: '100%',
    height: '100%',
    opacity: 0.3,
  },
  content: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    gap: tvSpacing.gap_lg,
    padding: tvSpacing.sectionPaddingLarge,
  },
  artwork: {
    width: 400,
    height: 400,
    borderRadius: tvRadii.card_lg,
  },
  trackInfo: {
    alignItems: 'center',
    gap: tvSpacing.gap_sm,
  },
  title: {
    fontSize: tvFontSizes['3xl'],
    color: tvColors.textPrimary,
    fontWeight: 'bold',
    textAlign: 'center',
  },
  artist: {
    fontSize: tvFontSizes.xl,
    color: tvColors.textSecondary,
    textAlign: 'center',
  },
  album: {
    fontSize: tvFontSizes.body,
    color: tvColors.textMuted,
    textAlign: 'center',
  },
  progressContainer: {
    width: '60%',
    gap: tvSpacing.gap_sm,
  },
  progressBar: {
    height: 4,
    backgroundColor: tvColors.border,
    borderRadius: 2,
    overflow: 'hidden',
  },
  progressFill: {
    height: '100%',
    backgroundColor: tvColors.primary,
  },
  progressText: {
    fontSize: tvFontSizes.sm,
    color: tvColors.textMuted,
    textAlign: 'center',
  },
});
