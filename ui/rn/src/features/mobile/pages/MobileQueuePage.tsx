import React from 'react';
import { View, Text, ScrollView, Pressable, StyleSheet } from 'react-native';
import { usePlayerStore } from '@/features/player/stores/player-store';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

export function MobileQueuePage() {
  const queue = usePlayerStore((s) => s.queue);
  const queueIndex = usePlayerStore((s) => s.queueIndex);

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <Text style={styles.title}>Queue</Text>
      {queue.length === 0 ? (
        <View style={styles.empty}>
          <Text style={styles.emptyText}>Nothing in queue</Text>
        </View>
      ) : (
        queue.map((track, i) => (
          <Pressable key={track.id} style={[styles.trackRow, i === queueIndex && styles.currentTrack]}>
            <Text style={[styles.trackIndex, i === queueIndex && styles.currentTrackText]}>
              {i === queueIndex ? '>' : `${i + 1}`}
            </Text>
            <View style={styles.trackInfo}>
              <Text style={[styles.trackTitle, i === queueIndex && styles.currentTrackText]} numberOfLines={1}>
                {track.title}
              </Text>
              <Text style={styles.trackArtist} numberOfLines={1}>{track.artistName}</Text>
            </View>
          </Pressable>
        ))
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.background,
  },
  content: {
    paddingBottom: 120,
  },
  title: {
    color: colors.foreground,
    fontSize: fontSizes.xl,
    fontWeight: '600',
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[4],
  },
  empty: {
    paddingVertical: spacing[8],
    alignItems: 'center',
  },
  emptyText: {
    color: colors.muted,
    fontSize: fontSizes.body,
  },
  trackRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[3],
    gap: spacing[3],
  },
  currentTrack: {
    backgroundColor: colors.card,
  },
  trackIndex: {
    color: colors.muted,
    fontSize: fontSizes.body,
    width: 24,
    textAlign: 'right',
  },
  currentTrackText: {
    color: colors.primary,
  },
  trackInfo: {
    flex: 1,
    minWidth: 0,
  },
  trackTitle: {
    color: colors.foreground,
    fontSize: fontSizes.body,
  },
  trackArtist: {
    color: colors.muted,
    fontSize: fontSizes.sm,
  },
});
