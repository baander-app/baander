/**
 * useSearch -- platform-agnostic hook for catalog search.
 *
 * Usage: `const { data, isLoading, error } = useSearch({ query: 'beatles' })`
 *
 * Debounces at 300ms as per plan requirements.
 */

import { useState, useEffect, useRef } from 'react';
import type { SearchResult } from '../api/catalog-api';
import { search } from '../api/catalog-api';

export interface UseSearchParams {
  query: string;
  type?: 'album' | 'artist' | 'song';
  page?: number;
  limit?: number;
  debounceMs?: number;
}

export interface UseSearchResult {
  data: SearchResult | null;
  isLoading: boolean;
  error: Error | null;
}

export function useSearch(params: UseSearchParams): UseSearchResult {
  const { query, type, page, limit, debounceMs = 300 } = params;
  const [data, setData] = useState<SearchResult | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<Error | null>(null);
  const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    // Clear previous timeout
    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current);
    }

    // Don't search for empty queries
    if (!query.trim()) {
      setData(null);
      setIsLoading(false);
      setError(null);
      return;
    }

    // Debounced search
    timeoutRef.current = setTimeout(async () => {
      let cancelled = false;
      setIsLoading(true);
      setError(null);

      try {
        const result = await search({ query, type, page, limit });
        if (!cancelled) {
          setData(result);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err : new Error('Search failed'));
        }
      } finally {
        if (!cancelled) {
          setIsLoading(false);
        }
      }
    }, debounceMs);

    return () => {
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current);
      }
    };
  }, [query, type, page, limit, debounceMs]);

  return {
    data,
    isLoading,
    error,
  };
}
