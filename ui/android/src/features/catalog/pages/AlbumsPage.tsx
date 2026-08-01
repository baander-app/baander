/**
 * AlbumsPage -- grid of album cards with blurhash images.
 *
 * Pull-to-refresh, navigation to AlbumDetail on tap.
 */

import React from 'react';
import { View, Text, Pressable, FlatList, RefreshControl, StyleSheet } from 'react-native';
import { useAlbums } from '../hooks/useAlbums';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

interface AlbumsPageProps {
  onAlbumPress: (albumPublicId: string) => void;
}

export function AlbumsPage({ onAlbumPress }: AlbumsPageProps) {
  const { data: albums, isLoading, error, refetch } = useAlbums();

  const renderItem = ({ item }: { item: typeof albums[number] }) => (
    <Pressable
      style={styles.card}
      onPress={() => onAlbumPress(item.publicId)}
    >
      {/* Blurhash placeholder for album cover */}
      <View style={styles.coverPlaceholder}>
        <Text style={styles.placeholderIcon}>&#9834;</Text>
      </View>
      <Text style={styles.albumTitle} numberOfLines={1}>{item.title}</Text>
      <Text style={styles.albumArtist} numberOfLines={1}>{item.artistName ?? 'Unknown'}</Text>
      {item.releaseYear != null && (
        <Text style={styles.albumYear}>{item.releaseYear}</Text>
      )}
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
        data={albums}
        keyExtractor={(item) => item.publicId}
        renderItem={renderItem}
        numColumns={2}
        columnWrapperStyle={styles.row}
        contentContainerStyle={styles.listContent}
        refreshControl={<RefreshControl refreshing={isLoading} onRefresh={refetch} />}
        ListEmptyComponent={
          !isLoading ? (
            <View style={styles.empty}>
              <Text style={styles.emptyText}>No albums found</Text>
            </View>
          ) : null
        }
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.background,
  },
  listContent: {
    padding: spacing[3],
    paddingBottom: 120,
  },
  row: {
    gap: spacing[3],
  },
  card: {
    flex: 1,
    maxWidth: '50%',
  },
  coverPlaceholder: {
    aspectRatio: 1,
    borderRadius: radii.lg,
    backgroundColor: colors.card,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing[2],
  },
  placeholderIcon: {
    color: colors.muted,
    fontSize: fontSizes['2xl'],
  },
  albumTitle: {
    color: colors.foreground,
    fontSize: fontSizes.body,
    fontWeight: '500',
  },
  albumArtist: {
    color: colors.muted,
    fontSize: fontSizes.sm,
  },
  albumYear: {
    color: colors.muted,
    fontSize: fontSizes.xs,
  },
  errorBar: {
    backgroundColor: colors.destructive,
    padding: spacing[3],
  },
  errorText: {
    color: colors.foreground,
    fontSize: fontSizes.sm,
  },
  empty: {
    alignItems: 'center',
    paddingVertical: spacing[8],
  },
  emptyText: {
    color: colors.muted,
    fontSize: fontSizes.body,
  },
});
