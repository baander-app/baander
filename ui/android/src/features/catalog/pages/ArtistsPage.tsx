/**
 * ArtistsPage -- grid of artist cards with blurhash images.
 *
 * Pull-to-refresh, navigation to ArtistDetail on tap.
 */

import React from 'react';
import { View, Text, Pressable, FlatList, RefreshControl, StyleSheet } from 'react-native';
import { useArtists } from '../hooks/useArtists';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

interface ArtistsPageProps {
  onArtistPress: (artistPublicId: string) => void;
}

export function ArtistsPage({ onArtistPress }: ArtistsPageProps) {
  const { data: artists, isLoading, error, refetch } = useArtists();

  const renderItem = ({ item }: { item: typeof artists[number] }) => (
    <Pressable
      style={styles.card}
      onPress={() => onArtistPress(item.publicId)}
    >
      {/* Blurhash placeholder for artist image */}
      <View style={styles.imagePlaceholder}>
        <Text style={styles.placeholderInitial}>
          {item.name.charAt(0).toUpperCase()}
        </Text>
      </View>
      <Text style={styles.artistName} numberOfLines={1}>{item.name}</Text>
      <Text style={styles.artistMeta}>
        {item.albumCount} album{item.albumCount !== 1 ? 's' : ''}
      </Text>
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
        data={artists}
        keyExtractor={(item) => item.publicId}
        renderItem={renderItem}
        numColumns={2}
        columnWrapperStyle={styles.row}
        contentContainerStyle={styles.listContent}
        refreshControl={<RefreshControl refreshing={isLoading} onRefresh={refetch} />}
        ListEmptyComponent={
          !isLoading ? (
            <View style={styles.empty}>
              <Text style={styles.emptyText}>No artists found</Text>
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
  imagePlaceholder: {
    aspectRatio: 1,
    borderRadius: radii.full,
    backgroundColor: colors.card,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing[2],
  },
  placeholderInitial: {
    color: colors.muted,
    fontSize: fontSizes['2xl'],
    fontWeight: '600',
  },
  artistName: {
    color: colors.foreground,
    fontSize: fontSizes.body,
    fontWeight: '500',
    textAlign: 'center',
  },
  artistMeta: {
    color: colors.muted,
    fontSize: fontSizes.sm,
    textAlign: 'center',
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
