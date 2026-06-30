/**
 * usePlaylists -- hooks for playlist listing and detail.
 *
 * usePlaylists() -- list all user playlists.
 * usePlaylist(publicId) -- fetch single playlist with tracks.
 */

import { useState, useEffect } from 'react';
import type { Playlist, PlaylistTrack } from '../api/playlist-api';
import {
  getPlaylists,
  getPlaylist as fetchPlaylist,
} from '../api/playlist-api';

/**
 * List all user playlists.
 */
export function usePlaylists() {
  const [data, setData] = useState<Playlist[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);
  const [key, setKey] = useState(0);

  useEffect(() => {
    let cancelled = false;

    async function fetch() {
      setIsLoading(true);
      setError(null);
      try {
        const result = await getPlaylists();
        if (!cancelled) setData(result);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err : new Error('Failed to fetch playlists'));
        }
      } finally {
        if (!cancelled) setIsLoading(false);
      }
    }

    fetch();
    return () => { cancelled = true; };
  }, [key]);

  return {
    data,
    isLoading,
    error,
    refetch: () => setKey((k) => k + 1),
  };
}

/**
 * Fetch a single playlist with tracks.
 */
export function usePlaylist(publicId: string | null) {
  const [playlist, setPlaylist] = useState<Playlist | null>(null);
  const [tracks, setTracks] = useState<PlaylistTrack[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<Error | null>(null);
  const [key, setKey] = useState(0);

  useEffect(() => {
    if (!publicId) return;

    let cancelled = false;

    async function fetch() {
      setIsLoading(true);
      setError(null);
      try {
        const result = await fetchPlaylist(publicId);
        if (!cancelled) {
          setPlaylist(result.playlist);
          setTracks(result.tracks);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err : new Error('Failed to fetch playlist'));
        }
      } finally {
        if (!cancelled) setIsLoading(false);
      }
    }

    fetch();
    return () => { cancelled = true; };
  }, [publicId, key]);

  return {
    playlist,
    tracks,
    isLoading,
    error,
    refetch: () => setKey((k) => k + 1),
  };
}
