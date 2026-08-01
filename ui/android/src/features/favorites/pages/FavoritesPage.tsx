/**
 * FavoritesPage -- list of favorited songs with unfavorite button.
 *
 * Pull-to-refresh. Tapping plays the song.
 */

import React from 'react';
import { View, Text, Pressable, FlatList, RefreshControl, StyleSheet } from 'react-native';
import { useFavorites } from '../hooks/useFavorites';
import { usePlayerStore } from '@/features/player/stores/player-store';
import { colors } from '@/shared/theme/colors';
import { spacing, fontSizes } from '@/shared/theme/tokens';

interface FavoritesPageProps {
  onTrackPress?: (songPublicId: string) => void;
}

export function FavoritesPage({ onTrackPress }: FavoritesPageProps) {
  const { favorites, isLoading, error, refetch, toggleFavorite } = useFavorites('song');

  const renderItem = ({ item }: { item: typeof favorites[number] }) => (
    <Pressable style={styles.row} onPress={() => onTrackPress?.(item.entityPublicId)}>
      <View style={styles.trackInfo}>
        <Text style={styles.trackTitle} numberOfLines={1}>{item.entityPublicId}</Text>
        <Text style={styles.trackArtist}>
          Added {new Date(item.createdAt).toLocaleDateString()}
        </Text>
      </View>
      <Pressable
        style={styles.unfavButton}
        onPress={() => toggleFavorite(item.entityType, item.entityPublicId)}
        hitSlop={8}
      >
        <Text style={styles.unfavText}>{'\u2665'}</Text>
      </Pressable>
    </Pressable>
  );

  return (
    <View style={styles.container}>
      {error && (
        <View style={styles.errorBar}>
          <Text style={styles.errorText}>{error.message}</Text>
        </View>
      )}

      <FlatList
        data={favorites}
        keyExtractor={(item) => item.publicId}
        renderItem={renderItem}
        contentContainerStyle={styles.listContent}
        refreshControl={<RefreshControl refreshing={isLoading} onRefresh={refetch} />}
        ListEmptyComponent={
          !isLoading ? (
            <View style={styles.empty}>
              <Text style={styles.emptyTitle}>No favorites yet</Text>
              <Text style={styles.emptySubtitle}>Heart a song to add it here</Text>
            </View>
          ) : null
        }
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.background },
  listContent: { paddingBottom: 120 },
  row: {
    flexDirection: 'row', alignItems: 'center',
    paddingHorizontal: spacing[4], paddingVertical: spacing[3],
    borderBottomWidth: 1, borderBottomColor: colors.border, gap: spacing[3],
  },
  trackInfo: { flex: 1, minWidth: 0 },
  trackTitle: { color: colors.foreground, fontSize: fontSizes.body, fontWeight: '500' },
  trackArtist: { color: colors.muted, fontSize: fontSizes.sm },
  unfavButton: { width: 36, height: 36, alignItems: 'center', justifyContent: 'center' },
  unfavText: { color: colors.primary, fontSize: fontSizes.lg },
  errorBar: { backgroundColor: colors.destructive, padding: spacing[3] },
  errorText: { color: colors.foreground, fontSize: fontSizes.sm },
  empty: { alignItems: 'center', paddingVertical: spacing[12] },
  emptyTitle: { color: colors.foreground, fontSize: fontSizes.lg, fontWeight: '600' },
  emptySubtitle: { color: colors.muted, fontSize: fontSizes.body, marginTop: spacing[1] },
});
