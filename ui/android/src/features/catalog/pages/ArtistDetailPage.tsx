/**
 * ArtistDetailPage -- artist cover + name, list of albums.
 *
 * Navigation to AlbumDetail on album tap.
 */

import React from 'react';
import {
  View, Text, Pressable, FlatList, ActivityIndicator, StyleSheet,
} from 'react-native';
import { useEffect, useState } from 'react';
import type { Artist, Album } from '../api/catalog-api';
import { getArtist, getArtistAlbums } from '../api/catalog-api';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

interface ArtistDetailPageProps {
  artistPublicId: string;
  onAlbumPress: (albumPublicId: string) => void;
  onBack: () => void;
}

export function ArtistDetailPage({ artistPublicId, onAlbumPress, onBack }: ArtistDetailPageProps) {
  const [artist, setArtist] = useState<Artist | null>(null);
  const [albums, setAlbums] = useState<Album[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function fetch() {
      setIsLoading(true);
      setError(null);
      try {
        const [artistData, albumData] = await Promise.all([
          getArtist(artistPublicId),
          getArtistAlbums(artistPublicId),
        ]);
        if (!cancelled) {
          setArtist(artistData);
          setAlbums(albumData);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err : new Error('Failed to load artist'));
        }
      } finally {
        if (!cancelled) {
          setIsLoading(false);
        }
      }
    }

    fetch();
    return () => { cancelled = true; };
  }, [artistPublicId]);

  if (isLoading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator size="large" color={colors.primary} />
      </View>
    );
  }

  if (error || !artist) {
    return (
      <View style={styles.centered}>
        <Text style={styles.errorText}>{error?.message ?? 'Artist not found'}</Text>
        <Pressable onPress={onBack}>
          <Text style={styles.backLink}>Go back</Text>
        </Pressable>
      </View>
    );
  }

  const renderAlbum = ({ item }: { item: Album }) => (
    <Pressable style={styles.albumRow} onPress={() => onAlbumPress(item.publicId)}>
      <View style={styles.albumCover}>
        <Text style={styles.albumIcon}>&#9834;</Text>
      </View>
      <View style={styles.albumInfo}>
        <Text style={styles.albumTitle} numberOfLines={1}>{item.title}</Text>
        <Text style={styles.albumMeta}>
          {item.releaseYear != null ? `${item.releaseYear} · ` : ''}
          {item.songCount} track{item.songCount !== 1 ? 's' : ''}
        </Text>
      </View>
    </Pressable>
  );

  return (
    <View style={styles.container}>
      {/* Artist header */}
      <View style={styles.header}>
        <View style={styles.artistImage}>
          <Text style={styles.artistInitial}>
            {artist.name.charAt(0).toUpperCase()}
          </Text>
        </View>
        <Text style={styles.artistName}>{artist.name}</Text>
        <Text style={styles.artistStats}>
          {artist.albumCount} album{artist.albumCount !== 1 ? 's' : ''}
          {' · '}
          {artist.songCount} song{artist.songCount !== 1 ? 's' : ''}
        </Text>
      </View>

      {/* Album list */}
      <FlatList
        data={albums}
        keyExtractor={(item) => item.publicId}
        renderItem={renderAlbum}
        contentContainerStyle={styles.listContent}
        ListEmptyComponent={
          <View style={styles.empty}>
            <Text style={styles.emptyText}>No albums</Text>
          </View>
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
  centered: {
    flex: 1,
    backgroundColor: colors.background,
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing[4],
  },
  header: {
    alignItems: 'center',
    paddingTop: spacing[8],
    paddingBottom: spacing[6],
    paddingHorizontal: spacing[4],
  },
  artistImage: {
    width: 120,
    height: 120,
    borderRadius: 60,
    backgroundColor: colors.card,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing[4],
  },
  artistInitial: {
    color: colors.muted,
    fontSize: fontSizes['4xl'],
    fontWeight: '700',
  },
  artistName: {
    color: colors.foreground,
    fontSize: fontSizes['2xl'],
    fontWeight: '700',
    textAlign: 'center',
  },
  artistStats: {
    color: colors.muted,
    fontSize: fontSizes.body,
    marginTop: spacing[1],
  },
  listContent: {
    paddingBottom: 160,
  },
  albumRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[3],
    gap: spacing[3],
  },
  albumCover: {
    width: 48,
    height: 48,
    borderRadius: radii.md,
    backgroundColor: colors.card,
    alignItems: 'center',
    justifyContent: 'center',
  },
  albumIcon: {
    color: colors.muted,
    fontSize: fontSizes.lg,
  },
  albumInfo: {
    flex: 1,
    minWidth: 0,
  },
  albumTitle: {
    color: colors.foreground,
    fontSize: fontSizes.body,
    fontWeight: '500',
  },
  albumMeta: {
    color: colors.muted,
    fontSize: fontSizes.sm,
  },
  errorText: {
    color: colors.destructive,
    fontSize: fontSizes.body,
    textAlign: 'center',
    marginBottom: spacing[4],
  },
  backLink: {
    color: colors.primary,
    fontSize: fontSizes.body,
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
