/**
 * useArtists -- hook for fetching artists listing.
 *
 * Usage: `const { data, isLoading, error, refetch } = useArtists({ page: 1, limit: 20 })`
 */

import { useState, useEffect } from 'react';
import type { Artist } from '../api/catalog-api';
import { getArtists } from '../api/catalog-api';

export interface UseArtistsParams {
  page?: number;
  limit?: number;
}

export interface UseArtistsResult {
  data: Artist[];
  isLoading: boolean;
  error: Error | null;
  refetch: () => void;
}

export function useArtists(params: UseArtistsParams = {}): UseArtistsResult {
  const [data, setData] = useState<Artist[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);
  const [key, setKey] = useState(0);

  useEffect(() => {
    let cancelled = false;

    async function fetch() {
      setIsLoading(true);
      setError(null);
      try {
        const result = await getArtists(params);
        if (!cancelled) {
          setData(result);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err : new Error('Failed to fetch artists'));
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
