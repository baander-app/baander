/**
 * Playlist API -- API client for playlist CRUD endpoints.
 *
 * Uses shared Axios instance with DPoP auth.
 */

import { catalogApi } from '@/features/catalog/api/catalog-api';

/**
 * Playlist types.
 */
export interface Playlist {
  uuid: string;
  publicId: string;
  name: string;
  description: string | null;
  isPublic: boolean;
  trackCount: number;
  duration: number;
  createdAt: string;
  updatedAt: string;
}

export interface PlaylistTrack {
  uuid: string;
  publicId: string;
  position: number;
  songPublicId: string;
  title: string;
  artistName: string | null;
  albumName: string | null;
  albumPublicId: string | null;
  duration: number | null;
  coverImageBlurhash: string | null;
}

/**
 * Fetch user's playlists.
 */
export async function getPlaylists(
  params: { page?: number; limit?: number } = {},
): Promise<Playlist[]> {
  const { data } = await catalogApi.get<{ data: Playlist[] }>('/api/playlists', {
    params: { page: params.page ?? 1, limit: params.limit ?? 20 },
  });
  return data.data;
}

/**
 * Fetch a single playlist with tracks.
 */
export async function getPlaylist(publicId: string): Promise<{
  playlist: Playlist;
  tracks: PlaylistTrack[];
}> {
  const { data } = await catalogApi.get<{
    data: { playlist: Playlist; tracks: PlaylistTrack[] };
  }>(`/api/playlists/${publicId}`);
  return data.data;
}

/**
 * Create a new playlist.
 */
export async function createPlaylist(params: {
  name: string;
  description?: string;
  isPublic?: boolean;
}): Promise<Playlist> {
  const { data } = await catalogApi.post<{ data: Playlist }>('/api/playlists', params);
  return data.data;
}

/**
 * Update playlist metadata.
 */
export async function updatePlaylist(
  publicId: string,
  params: { name?: string; description?: string; isPublic?: boolean },
): Promise<Playlist> {
  const { data } = await catalogApi.put<{ data: Playlist }>(
    `/api/playlists/${publicId}`,
    params,
  );
  return data.data;
}

/**
 * Delete a playlist.
 */
export async function deletePlaylist(publicId: string): Promise<void> {
  await catalogApi.delete(`/api/playlists/${publicId}`);
}

/**
 * Add a song to a playlist.
 */
export async function addSongToPlaylist(
  playlistPublicId: string,
  songPublicId: string,
): Promise<void> {
  await catalogApi.post(`/api/playlists/${playlistPublicId}/tracks`, { songPublicId });
}

/**
 * Remove a song from a playlist.
 */
export async function removeSongFromPlaylist(
  playlistPublicId: string,
  trackPublicId: string,
): Promise<void> {
  await catalogApi.delete(`/api/playlists/${playlistPublicId}/tracks/${trackPublicId}`);
}

/**
 * Reorder playlist songs.
 */
export async function reorderPlaylistSongs(
  playlistPublicId: string,
  trackPublicId: string,
  newPosition: number,
): Promise<void> {
  await catalogApi.put(
    `/api/playlists/${playlistPublicId}/tracks/${trackPublicId}/reorder`,
    { position: newPosition },
  );
}
