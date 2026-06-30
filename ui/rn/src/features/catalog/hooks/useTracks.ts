/**
 * useTracks -- platform-agnostic hook for fetching tracks.
 *
 * Usage: `const { data, isLoading, error } = useTracks('album-public-id')`
 */

import { useState, useEffect } from 'react';
import type { Song } from '../api/catalog-api';
import { getAlbumTracks } from '../api/catalog-api';

export interface UseTracksResult {
  data: Song[];
  isLoading: boolean;
  error: Error | null;
  refetch: () => void;
}

export function useTracks(albumPublicId: string | null): UseTracksResult {
  const [data, setData] = useState<Song[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<Error | null>(null);
  const [key, setKey] = useState(0);

  useEffect(() => {
    if (!albumPublicId) {
      setData([]);
      setIsLoading(false);
      setError(null);
      return;
    }

    let cancelled = false;

    async function fetch() {
      setIsLoading(true);
      setError(null);
      try {
        const result = await getAlbumTracks(albumPublicId);
        if (!cancelled) {
          setData(result);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err : new Error('Failed to fetch tracks'));
        }
      } finally {
        if (!cancelled) {
          setIsLoading(false);
        }
      }
    }

    fetch();

    return () => {
      cancelled = true;
    };
  }, [albumPublicId, key]);

  return {
    data,
    isLoading,
    error,
    refetch: () => setKey((k) => k + 1),
  };
}
