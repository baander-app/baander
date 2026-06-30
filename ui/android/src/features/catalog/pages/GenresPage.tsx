/**
 * GenresPage -- list of genres with album counts.
 *
 * Pull-to-refresh. Tapping navigates to filtered albums view.
 */

import React from 'react';
import { View, Text, Pressable, FlatList, RefreshControl, StyleSheet } from 'react-native';
import { useGenres } from '../hooks/useGenres';
import { colors } from '@/shared/theme/colors';
import { spacing, fontSizes } from '@/shared/theme/tokens';

interface GenresPageProps {
  onGenrePress?: (genrePublicId: string, genreName: string) => void;
}

export function GenresPage({ onGenrePress }: GenresPageProps) {
  const { data: genres, isLoading, error, refetch } = useGenres();

  const renderItem = ({ item, index }: { item: typeof genres[number]; index: number }) => (
    <Pressable
      style={[styles.row, index % 2 === 0 && styles.rowAlt]}
      onPress={() => onGenrePress?.(item.publicId, item.name)}
    >
      <View style={styles.genreInfo}>
        <Text style={styles.genreName}>{item.name}</Text>
      </View>
      <Text style={styles.albumCount}>
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
        data={genres}
        keyExtractor={(item) => item.publicId}
        renderItem={renderItem}
        contentContainerStyle={styles.listContent}
        refreshControl={<RefreshControl refreshing={isLoading} onRefresh={refetch} />}
        ListEmptyComponent={
          !isLoading ? (
            <View style={styles.empty}>
              <Text style={styles.emptyText}>No genres found</Text>
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
    paddingBottom: 120,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[3],
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  rowAlt: {
    backgroundColor: colors.card,
  },
  genreInfo: {
    flex: 1,
    minWidth: 0,
  },
  genreName: {
    color: colors.foreground,
    fontSize: fontSizes.body,
    fontWeight: '500',
  },
  albumCount: {
    color: colors.muted,
    fontSize: fontSizes.sm,
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
