/**
 * useSongs -- hook for fetching songs listing, optionally filtered by album.
 *
 * Usage: `const { data, isLoading, error, refetch } = useSongs({ albumPublicId: 'abc' })`
 */

import { useState, useEffect } from 'react';
import type { Song } from '../api/catalog-api';
import { getSongs } from '../api/catalog-api';

export interface UseSongsParams {
  albumPublicId?: string;
  page?: number;
  limit?: number;
}

export interface UseSongsResult {
  data: Song[];
  isLoading: boolean;
  error: Error | null;
  refetch: () => void;
}

export function useSongs(params: UseSongsParams = {}): UseSongsResult {
  const [data, setData] = useState<Song[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);
  const [key, setKey] = useState(0);

  useEffect(() => {
    let cancelled = false;

    async function fetch() {
      setIsLoading(true);
      setError(null);
      try {
        const result = await getSongs(params);
        if (!cancelled) {
          setData(result);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err : new Error('Failed to fetch songs'));
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
  }, [params.albumPublicId, params.page, params.limit, key]);

  return {
    data,
    isLoading,
    error,
    refetch: () => setKey((k) => k + 1),
  };
}
