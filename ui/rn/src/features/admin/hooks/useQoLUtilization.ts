/**
 * useQoLUtilization -- poll QoL utilization data for the utilization card.
 * Auto-refreshes every 5 seconds.
 */

import { useState, useEffect } from 'react';
import { getAuthSnapshot } from '@/features/auth/stores/auth-store';
import Axios from 'axios';

export interface QoLUtilization {
  state: 'learning' | 'active';
  budget_cap: number;
  active_streams: number;
}

export interface UseQoLUtilizationResult {
  data: QoLUtilization | null;
  isLoading: boolean;
  error: Error | null;
}

const POLL_INTERVAL_MS = 5000;

export function useQoLUtilization(): UseQoLUtilizationResult {
  const [data, setData] = useState<QoLUtilization | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);

  useEffect(() => {
    let cancelled = false;
    let interval: ReturnType<typeof setInterval> | null = null;

    async function fetch() {
      const auth = getAuthSnapshot();
      if (!auth.serverUrl || !auth.isAuthenticated) {
        setIsLoading(false);
        return;
      }

      try {
        const { data: response } = await Axios.get<{ data: QoLUtilization }>(
          `${auth.serverUrl}/api/admin/qol/status`,
          { headers: auth.accessToken ? { Authorization: `Bearer ${auth.accessToken}` } : {} },
        );

        if (!cancelled) {
          setData(response.data);
          setIsLoading(false);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err : new Error('Failed to fetch utilization'));
          setIsLoading(false);
        }
      }
    }

    fetch();
    interval = setInterval(fetch, POLL_INTERVAL_MS);

    return () => {
      cancelled = true;
      if (interval) clearInterval(interval);
    };
  }, []);

  return { data, isLoading, error };
}
