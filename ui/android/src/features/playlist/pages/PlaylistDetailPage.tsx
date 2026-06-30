/**
 * PlaylistDetailPage -- playlist name + description, track list.
 *
 * Delete button, add-to-queue on track tap.
 */

import React from 'react';
import {
  View, Text, Pressable, FlatList, Alert, ActivityIndicator, StyleSheet,
} from 'react-native';
import { usePlaylist } from '../hooks/usePlaylists';
import { deletePlaylist } from '../api/playlist-api';
import { usePlayerStore } from '@/features/player/stores/player-store';
import type { Track } from '@/features/player/stores/player-store';
import type { PlaylistTrack } from '../api/playlist-api';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

interface PlaylistDetailPageProps {
  playlistPublicId: string;
  onBack: () => void;
  onDeleted: () => void;
}

function formatDuration(seconds: number): string {
  const mins = Math.floor(seconds / 60);
  const secs = Math.floor(seconds % 60);
  return `${mins}:${secs.toString().padStart(2, '0')}`;
}

function playlistTrackToTrack(pt: PlaylistTrack): Track {
  return {
    id: pt.uuid,
    publicId: pt.songPublicId,
    title: pt.title,
    artistName: pt.artistName,
    albumName: pt.albumName,
    albumPublicId: pt.albumPublicId,
    duration: pt.duration,
    trackNumber: null,
    discNumber: null,
    coverImageBlurhash: pt.coverImageBlurhash,
  };
}

export function PlaylistDetailPage({
  playlistPublicId,
  onBack,
  onDeleted,
}: PlaylistDetailPageProps) {
  const { playlist, tracks, isLoading, error, refetch } = usePlaylist(playlistPublicId);
  const playTrack = usePlayerStore((s) => s.playTrack);

  const handleTrackPress = (index: number) => {
    const trackList = tracks.map(playlistTrackToTrack);
    playTrack(trackList[index], trackList, index);
  };

  const handleDelete = () => {
    Alert.alert(
      'Delete Playlist',
      `Are you sure you want to delete "${playlist?.name ?? 'this playlist'}"?`,
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Delete',
          style: 'destructive',
          onPress: async () => {
            try {
              await deletePlaylist(playlistPublicId);
              onDeleted();
            } catch {
              Alert.alert('Error', 'Failed to delete playlist');
            }
          },
        },
      ],
    );
  };

  if (isLoading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator size="large" color={colors.primary} />
      </View>
    );
  }

  if (error || !playlist) {
    return (
      <View style={styles.centered}>
        <Text style={styles.errorText}>{error?.message ?? 'Playlist not found'}</Text>
        <Pressable onPress={onBack}>
          <Text style={styles.backLink}>Go back</Text>
        </Pressable>
      </View>
    );
  }

  const renderTrack = ({ item, index }: { item: PlaylistTrack; index: number }) => (
    <Pressable style={styles.trackRow} onPress={() => handleTrackPress(index)}>
      <Text style={styles.trackPosition}>{item.position}</Text>
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
      <View style={styles.header}>
        <Text style={styles.playlistName}>{playlist.name}</Text>
        {playlist.description && (
          <Text style={styles.playlistDescription}>{playlist.description}</Text>
        )}
        <Text style={styles.playlistMeta}>
          {playlist.trackCount} track{playlist.trackCount !== 1 ? 's' : ''}
          {playlist.isPublic ? ' · Public' : ' · Private'}
        </Text>
      </View>

      {tracks.length > 0 && (
        <Pressable style={styles.playAllButton} onPress={() => handleTrackPress(0)}>
          <Text style={styles.playAllText}>Play All</Text>
        </Pressable>
      )}

      <FlatList
        data={tracks}
        keyExtractor={(item) => item.publicId}
        renderItem={renderTrack}
        contentContainerStyle={styles.listContent}
        ListEmptyComponent={
          <View style={styles.empty}>
            <Text style={styles.emptyText}>No tracks in this playlist</Text>
          </View>
        }
      />

      <View style={styles.footer}>
        <Pressable style={styles.deleteButton} onPress={handleDelete}>
          <Text style={styles.deleteText}>Delete Playlist</Text>
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.background },
  centered: {
    flex: 1, backgroundColor: colors.background,
    alignItems: 'center', justifyContent: 'center', padding: spacing[4],
  },
  header: { paddingTop: spacing[8], paddingBottom: spacing[4], paddingHorizontal: spacing[4] },
  playlistName: { color: colors.foreground, fontSize: fontSizes['2xl'], fontWeight: '700' },
  playlistDescription: { color: colors.muted, fontSize: fontSizes.body, marginTop: spacing[1] },
  playlistMeta: { color: colors.muted, fontSize: fontSizes.sm, marginTop: spacing[2] },
  playAllButton: {
    backgroundColor: colors.primary, marginHorizontal: spacing[4],
    paddingHorizontal: spacing[8], paddingVertical: spacing[3],
    borderRadius: radii.full, alignItems: 'center', marginBottom: spacing[4],
  },
  playAllText: { color: colors.foreground, fontSize: fontSizes.body, fontWeight: '600' },
  listContent: { paddingBottom: 100 },
  trackRow: {
    flexDirection: 'row', alignItems: 'center',
    paddingHorizontal: spacing[4], paddingVertical: spacing[3], gap: spacing[3],
  },
  trackPosition: { color: colors.muted, fontSize: fontSizes.body, width: 28, textAlign: 'right' },
  trackInfo: { flex: 1, minWidth: 0 },
  trackTitle: { color: colors.foreground, fontSize: fontSizes.body },
  trackArtist: { color: colors.muted, fontSize: fontSizes.sm },
  trackDuration: { color: colors.muted, fontSize: fontSizes.sm },
  footer: { padding: spacing[4], borderTopWidth: 1, borderTopColor: colors.border },
  deleteButton: {
    backgroundColor: colors.destructive, paddingVertical: spacing[3],
    borderRadius: radii.md, alignItems: 'center',
  },
  deleteText: { color: colors.foreground, fontSize: fontSizes.body, fontWeight: '600' },
  errorText: {
    color: colors.destructive, fontSize: fontSizes.body,
    textAlign: 'center', marginBottom: spacing[4],
  },
  backLink: { color: colors.primary, fontSize: fontSizes.body },
  empty: { alignItems: 'center', paddingVertical: spacing[8] },
  emptyText: { color: colors.muted, fontSize: fontSizes.body },
});
