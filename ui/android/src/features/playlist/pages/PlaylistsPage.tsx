/**
 * PlaylistsPage -- list of user's playlists with FAB to create new.
 *
 * Pull-to-refresh. Tapping navigates to PlaylistDetail.
 */

import React from 'react';
import { View, Text, Pressable, FlatList, RefreshControl, StyleSheet } from 'react-native';
import { usePlaylists } from '../hooks/usePlaylists';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

interface PlaylistsPageProps {
  onPlaylistPress: (playlistPublicId: string) => void;
  onCreatePress: () => void;
}

export function PlaylistsPage({ onPlaylistPress, onCreatePress }: PlaylistsPageProps) {
  const { data: playlists, isLoading, error, refetch } = usePlaylists();

  const renderItem = ({ item }: { item: typeof playlists[number] }) => (
    <Pressable style={styles.row} onPress={() => onPlaylistPress(item.publicId)}>
      <View style={styles.playlistIcon}>
        <Text style={styles.iconText}>{'\u2261'}</Text>
      </View>
      <View style={styles.playlistInfo}>
        <Text style={styles.playlistName} numberOfLines={1}>{item.name}</Text>
        <Text style={styles.playlistMeta}>
          {item.trackCount} track{item.trackCount !== 1 ? 's' : ''}
          {item.isPublic ? ' · Public' : ''}
        </Text>
      </View>
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
        data={playlists}
        keyExtractor={(item) => item.publicId}
        renderItem={renderItem}
        contentContainerStyle={styles.listContent}
        refreshControl={<RefreshControl refreshing={isLoading} onRefresh={refetch} />}
        ListEmptyComponent={
          !isLoading ? (
            <View style={styles.empty}>
              <Text style={styles.emptyTitle}>No playlists yet</Text>
              <Text style={styles.emptySubtitle}>Create one to get started</Text>
            </View>
          ) : null
        }
      />

      {/* FAB */}
      <Pressable style={styles.fab} onPress={onCreatePress}>
        <Text style={styles.fabText}>+</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.background },
  listContent: { paddingBottom: 160 },
  row: {
    flexDirection: 'row', alignItems: 'center',
    paddingHorizontal: spacing[4], paddingVertical: spacing[3],
    borderBottomWidth: 1, borderBottomColor: colors.border, gap: spacing[3],
  },
  playlistIcon: {
    width: 48, height: 48, borderRadius: radii.md,
    backgroundColor: colors.card, alignItems: 'center', justifyContent: 'center',
  },
  iconText: { color: colors.muted, fontSize: fontSizes.lg },
  playlistInfo: { flex: 1, minWidth: 0 },
  playlistName: { color: colors.foreground, fontSize: fontSizes.body, fontWeight: '500' },
  playlistMeta: { color: colors.muted, fontSize: fontSizes.sm },
  errorBar: { backgroundColor: colors.destructive, padding: spacing[3] },
  errorText: { color: colors.foreground, fontSize: fontSizes.sm },
  empty: { alignItems: 'center', paddingVertical: spacing[12] },
  emptyTitle: { color: colors.foreground, fontSize: fontSizes.lg, fontWeight: '600' },
  emptySubtitle: { color: colors.muted, fontSize: fontSizes.body, marginTop: spacing[1] },
  fab: {
    position: 'absolute', bottom: 140, right: spacing[4],
    width: 56, height: 56, borderRadius: 28,
    backgroundColor: colors.primary, alignItems: 'center', justifyContent: 'center',
    elevation: 4,
  },
  fabText: { color: colors.foreground, fontSize: 28, fontWeight: '300' },
});
