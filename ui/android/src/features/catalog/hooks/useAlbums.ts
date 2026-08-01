/**
 * useAlbums -- hook for fetching albums listing.
 *
 * Usage: `const { data, isLoading, error, refetch } = useAlbums({ page: 1, limit: 20 })`
 */

import { useState, useEffect } from 'react';
import type { Album } from '../api/catalog-api';
import { getAlbums } from '../api/catalog-api';

export interface UseAlbumsParams {
  page?: number;
  limit?: number;
}

export interface UseAlbumsResult {
  data: Album[];
  isLoading: boolean;
  error: Error | null;
  refetch: () => void;
}

export function useAlbums(params: UseAlbumsParams = {}): UseAlbumsResult {
  const [data, setData] = useState<Album[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);
  const [key, setKey] = useState(0);

  useEffect(() => {
    let cancelled = false;

    async function fetch() {
      setIsLoading(true);
      setError(null);
      try {
        const result = await getAlbums(params);
        if (!cancelled) {
          setData(result);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err : new Error('Failed to fetch albums'));
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
  }, [params.page, params.limit, key]);

  return {
    data,
    isLoading,
    error,
    refetch: () => setKey((k) => k + 1),
  };
}
