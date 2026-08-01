/**
 * useGenres -- platform-agnostic hook for fetching genres.
 *
 * Usage: `const { data, isLoading, error } = useGenres()`
 */

import { useState, useEffect } from 'react';
import type { Genre } from '../api/catalog-api';
import { getGenres } from '../api/catalog-api';

export interface UseGenresResult {
  data: Genre[];
  isLoading: boolean;
  error: Error | null;
  refetch: () => void;
}

export function useGenres(): UseGenresResult {
  const [data, setData] = useState<Genre[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);
  const [key, setKey] = useState(0);

  useEffect(() => {
    let cancelled = false;

    async function fetch() {
      setIsLoading(true);
      setError(null);
      try {
        const result = await getGenres();
        if (!cancelled) {
          setData(result);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err : new Error('Failed to fetch genres'));
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
  }, [key]);

  return {
    data,
    isLoading,
    error,
    refetch: () => setKey((k) => k + 1),
  };
}
