/**
 * AlbumDetailPage -- album cover + title + year, track list.
 *
 * Tapping track starts playback via player store.
 */

import React from 'react';
import {
  View, Text, Pressable, FlatList, ActivityIndicator, StyleSheet,
} from 'react-native';
import { useEffect, useState } from 'react';
import type { Album, Song } from '../api/catalog-api';
import { getAlbum, getAlbumTracks } from '../api/catalog-api';
import { usePlayerStore } from '@/features/player/stores/player-store';
import type { Track } from '@/features/player/stores/player-store';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

interface AlbumDetailPageProps {
  albumPublicId: string;
  onBack: () => void;
}

/** Convert a Song to a player Track. */
function songToTrack(song: Song): Track {
  return {
    id: song.uuid,
    publicId: song.publicId,
    title: song.title,
    artistName: song.artistName,
    albumName: song.albumName,
    albumPublicId: song.albumPublicId,
    duration: song.duration,
    trackNumber: song.trackNumber,
    discNumber: song.discNumber,
    coverImageBlurhash: song.coverImageBlurhash,
  };
}

/** Format seconds to m:ss. */
function formatDuration(seconds: number): string {
  const mins = Math.floor(seconds / 60);
  const secs = Math.floor(seconds % 60);
  return `${mins}:${secs.toString().padStart(2, '0')}`;
}

export function AlbumDetailPage({ albumPublicId, onBack }: AlbumDetailPageProps) {
  const [album, setAlbum] = useState<Album | null>(null);
  const [tracks, setTracks] = useState<Song[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);
  const playTrack = usePlayerStore((s) => s.playTrack);

  useEffect(() => {
    let cancelled = false;

    async function fetch() {
      setIsLoading(true);
      setError(null);
      try {
        const [albumData, tracksData] = await Promise.all([
          getAlbum(albumPublicId),
          getAlbumTracks(albumPublicId),
        ]);
        if (!cancelled) {
          setAlbum(albumData);
          setTracks(tracksData);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err : new Error('Failed to load album'));
        }
      } finally {
        if (!cancelled) {
          setIsLoading(false);
        }
      }
    }

    fetch();
    return () => { cancelled = true; };
  }, [albumPublicId]);

  const handleTrackPress = (index: number) => {
    const trackList = tracks.map(songToTrack);
    playTrack(trackList[index], trackList, index);
  };

  if (isLoading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator size="large" color={colors.primary} />
      </View>
    );
  }

  if (error || !album) {
    return (
      <View style={styles.centered}>
        <Text style={styles.errorText}>{error?.message ?? 'Album not found'}</Text>
        <Pressable onPress={onBack}>
          <Text style={styles.backLink}>Go back</Text>
        </Pressable>
      </View>
    );
  }

  const renderTrack = ({ item, index }: { item: Song; index: number }) => (
    <Pressable style={styles.trackRow} onPress={() => handleTrackPress(index)}>
      <Text style={styles.trackNumber}>
        {item.trackNumber ?? index + 1}
      </Text>
      <View style={styles.trackInfo}>
        <Text style={styles.trackTitle} numberOfLines={1}>{item.title}</Text>
        <Text style={styles.trackArtist} numberOfLines={1}>
          {item.artistName ?? 'Unknown'}
        </Text>
      </View>
      {item.duration != null && (
        <Text style={styles.trackDuration}>{formatDuration(item.duration)}</Text>
      )}
    </Pressable>
  );

  return (
    <View style={styles.container}>
      {/* Album header */}
      <View style={styles.header}>
        <View style={styles.albumCover}>
          <Text style={styles.albumIcon}>&#9834;</Text>
        </View>
        <Text style={styles.albumTitle}>{album.title}</Text>
        <Text style={styles.albumMeta}>
          {album.artistName ?? 'Unknown'}
          {album.releaseYear != null ? ` · ${album.releaseYear}` : ''}
        </Text>
        <Text style={styles.albumStats}>
          {album.songCount} track{album.songCount !== 1 ? 's' : ''}
        </Text>

        {/* Play all button */}
        <Pressable
          style={styles.playAllButton}
          onPress={() => { if (tracks.length > 0) handleTrackPress(0); }}
        >
          <Text style={styles.playAllText}>Play All</Text>
        </Pressable>
      </View>

      {/* Track list */}
      <FlatList
        data={tracks}
        keyExtractor={(item) => item.publicId}
        renderItem={renderTrack}
        contentContainerStyle={styles.listContent}
        ListEmptyComponent={
          <View style={styles.empty}>
            <Text style={styles.emptyText}>No tracks</Text>
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
    paddingBottom: spacing[4],
    paddingHorizontal: spacing[4],
  },
  albumCover: {
    width: 200,
    height: 200,
    borderRadius: radii.lg,
    backgroundColor: colors.card,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing[4],
  },
  albumIcon: {
    color: colors.muted,
    fontSize: fontSizes['4xl'],
  },
  albumTitle: {
    color: colors.foreground,
    fontSize: fontSizes['2xl'],
    fontWeight: '700',
    textAlign: 'center',
  },
  albumMeta: {
    color: colors.muted,
    fontSize: fontSizes.body,
    marginTop: spacing[1],
  },
  albumStats: {
    color: colors.muted,
    fontSize: fontSizes.sm,
    marginTop: spacing[1],
  },
  playAllButton: {
    marginTop: spacing[4],
    backgroundColor: colors.primary,
    paddingHorizontal: spacing[8],
    paddingVertical: spacing[3],
    borderRadius: radii.full,
  },
  playAllText: {
    color: colors.foreground,
    fontSize: fontSizes.body,
    fontWeight: '600',
  },
  listContent: {
    paddingBottom: 160,
  },
  trackRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[3],
    gap: spacing[3],
  },
  trackNumber: {
    color: colors.muted,
    fontSize: fontSizes.body,
    width: 28,
    textAlign: 'right',
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
  trackDuration: {
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
