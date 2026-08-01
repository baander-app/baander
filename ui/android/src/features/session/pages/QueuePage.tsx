/**
 * QueuePage -- current queue with drag-to-reorder, swipe-to-remove, clear queue.
 *
 * Shows all tracks in the player queue with reordering support.
 */

import React, { useRef } from 'react';
import {
  View, Text, Pressable, FlatList, Alert, Animated, PanResponder, StyleSheet,
} from 'react-native';
import { usePlayerStore } from '@/features/player/stores/player-store';
import type { Track } from '@/features/player/stores/player-store';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

/** Format seconds to m:ss. */
function formatDuration(seconds: number): string {
  const mins = Math.floor(seconds / 60);
  const secs = Math.floor(seconds % 60);
  return `${mins}:${secs.toString().padStart(2, '0')}`;
}

interface QueueItemProps {
  track: Track;
  index: number;
  isCurrent: boolean;
  onPress: (index: number) => void;
  onRemove: (index: number) => void;
}

function QueueItem({ track, index, isCurrent, onPress, onRemove }: QueueItemProps) {
  const translateX = useRef(new Animated.Value(0)).current;

  const panResponder = useRef(
    PanResponder.create({
      onMoveShouldSetPanResponder: (_, gs) => Math.abs(gs.dx) > 20 && Math.abs(gs.dy) < 30,
      onPanResponderMove: (_, gs) => {
        if (gs.dx < 0) {
          translateX.setValue(Math.max(gs.dx, -120));
        }
      },
      onPanResponderRelease: (_, gs) => {
        if (gs.dx < -80) {
          onRemove(index);
        }
        Animated.spring(translateX, { toValue: 0, useNativeDriver: true }).start();
      },
    }),
  ).current;

  return (
    <View style={styles.itemContainer}>
      {/* Remove action behind swipe */}
      <View style={styles.behindActions}>
        <Pressable style={styles.removeAction} onPress={() => onRemove(index)}>
          <Text style={styles.removeText}>Remove</Text>
        </Pressable>
      </View>

      <Animated.View
        style={[styles.itemRow, { transform: [{ translateX }] }]}
        {...panResponder.panHandlers}
      >
        <Pressable
          style={[styles.itemContent, isCurrent && styles.currentItem]}
          onPress={() => onPress(index)}
        >
          <Text style={styles.position}>{index + 1}</Text>
          <View style={styles.trackInfo}>
            <Text style={[styles.trackTitle, isCurrent && styles.currentTitle]} numberOfLines={1}>
              {track.title}
            </Text>
            <Text style={styles.trackArtist} numberOfLines={1}>
              {track.artistName ?? 'Unknown'}
            </Text>
          </View>
          {track.duration != null && (
            <Text style={styles.trackDuration}>{formatDuration(track.duration)}</Text>
          )}
        </Pressable>
      </Animated.View>
    </View>
  );
}

interface QueuePageProps {
  onClose: () => void;
}

export function QueuePage({ onClose }: QueuePageProps) {
  const queue = usePlayerStore((s) => s.queue);
  const queueIndex = usePlayerStore((s) => s.queueIndex);
  const playTrack = usePlayerStore((s) => s.playTrack);
  const clearQueue = usePlayerStore((s) => s.clearQueue);

  const handleItemPress = (index: number) => {
    const track = queue[index];
    if (track) {
      playTrack(track, queue, index);
    }
  };

  const handleRemove = (index: number) => {
    const newQueue = [...queue];
    newQueue.splice(index, 1);
    const currentIndex = usePlayerStore.getState().queueIndex;

    usePlayerStore.setState({
      queue: newQueue,
      queueIndex: index <= currentIndex ? Math.max(0, currentIndex - 1) : currentIndex,
    });
  };

  const handleClearQueue = () => {
    Alert.alert('Clear Queue', 'Remove all tracks from the queue?', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Clear',
        style: 'destructive',
        onPress: clearQueue,
      },
    ]);
  };

  const renderItem = ({ item, index }: { item: Track; index: number }) => (
    <QueueItem
      track={item}
      index={index}
      isCurrent={index === queueIndex}
      onPress={handleItemPress}
      onRemove={handleRemove}
    />
  );

  return (
    <View style={styles.container}>
      <View style={styles.header}>
        <Text style={styles.headerTitle}>Queue</Text>
        <Text style={styles.headerMeta}>
          {queue.length} track{queue.length !== 1 ? 's' : ''}
        </Text>
      </View>

      {queueIndex >= 0 && queue[queueIndex] && (
        <View style={styles.nowPlaying}>
          <Text style={styles.nowPlayingLabel}>Now Playing</Text>
          <Text style={styles.nowPlayingTitle} numberOfLines={1}>
            {queue[queueIndex].title}
          </Text>
        </View>
      )}

      <FlatList
        data={queue}
        keyExtractor={(item, index) => `${item.id}-${index}`}
        renderItem={renderItem}
        contentContainerStyle={styles.listContent}
        ListEmptyComponent={
          <View style={styles.empty}>
            <Text style={styles.emptyText}>Queue is empty</Text>
            <Text style={styles.emptySubtitle}>Play a song to start the queue</Text>
          </View>
        }
      />

      {queue.length > 0 && (
        <View style={styles.footer}>
          <Pressable style={styles.clearButton} onPress={handleClearQueue}>
            <Text style={styles.clearText}>Clear Queue</Text>
          </Pressable>
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.background },
  header: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
    paddingHorizontal: spacing[4], paddingTop: 60, paddingBottom: spacing[4],
  },
  headerTitle: { color: colors.foreground, fontSize: fontSizes['2xl'], fontWeight: '700' },
  headerMeta: { color: colors.muted, fontSize: fontSizes.body },
  nowPlaying: {
    backgroundColor: colors.card, paddingHorizontal: spacing[4], paddingVertical: spacing[3],
    borderBottomWidth: 1, borderBottomColor: colors.border,
  },
  nowPlayingLabel: {
    color: colors.primary, fontSize: fontSizes.xs, fontWeight: '600', textTransform: 'uppercase',
  },
  nowPlayingTitle: {
    color: colors.foreground, fontSize: fontSizes.body, fontWeight: '500', marginTop: spacing[1],
  },
  listContent: { paddingBottom: 100 },
  itemContainer: { overflow: 'hidden' },
  behindActions: {
    ...StyleSheet.absoluteFillObject,
    flexDirection: 'row', justifyContent: 'flex-end', backgroundColor: colors.destructive,
  },
  removeAction: { width: 100, height: '100%', alignItems: 'center', justifyContent: 'center' },
  removeText: { color: colors.foreground, fontSize: fontSizes.sm, fontWeight: '600' },
  itemRow: { backgroundColor: colors.background },
  itemContent: {
    flexDirection: 'row', alignItems: 'center',
    paddingHorizontal: spacing[4], paddingVertical: spacing[3], gap: spacing[3],
  },
  currentItem: { backgroundColor: colors.card },
  position: { color: colors.muted, fontSize: fontSizes.body, width: 28, textAlign: 'right' },
  trackInfo: { flex: 1, minWidth: 0 },
  trackTitle: { color: colors.foreground, fontSize: fontSizes.body },
  currentTitle: { color: colors.primary, fontWeight: '600' },
  trackArtist: { color: colors.muted, fontSize: fontSizes.sm },
  trackDuration: { color: colors.muted, fontSize: fontSizes.sm },
  footer: { padding: spacing[4], borderTopWidth: 1, borderTopColor: colors.border },
  clearButton: {
    backgroundColor: colors.card, borderWidth: 1, borderColor: colors.destructive,
    paddingVertical: spacing[3], borderRadius: radii.md, alignItems: 'center',
  },
  clearText: { color: colors.destructive, fontSize: fontSizes.body, fontWeight: '600' },
  empty: { alignItems: 'center', paddingVertical: spacing[12] },
  emptyText: { color: colors.foreground, fontSize: fontSizes.lg, fontWeight: '600' },
  emptySubtitle: { color: colors.muted, fontSize: fontSizes.body, marginTop: spacing[1] },
});
