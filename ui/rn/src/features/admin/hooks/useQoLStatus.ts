/**
 * useQoLStatus -- fetch QoL stream governor status.
 */

import { useState, useEffect } from 'react';
import { getAuthSnapshot } from '@/features/auth/stores/auth-store';
import Axios from 'axios';

export interface QoLStatus {
  state: 'learning' | 'active';
  profile: 'conservative' | 'balanced' | 'aggressive';
  active_streams: number;
  sample_count: number;
  model_ready: boolean;
  budget_cap: number;
}

export interface QoLStream {
  job_id: string;
  quality_tier: string;
  predicted_cost: number;
}

export interface UseQoLStatusResult {
  status: QoLStatus | null;
  streams: QoLStream[];
  isLoading: boolean;
  error: Error | null;
  refetch: () => void;
}

export function useQoLStatus(): UseQoLStatusResult {
  const [status, setStatus] = useState<QoLStatus | null>(null);
  const [streams, setStreams] = useState<QoLStream[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);
  const [key, setKey] = useState(0);

  useEffect(() => {
    let cancelled = false;

    async function fetch() {
      setIsLoading(true);
      setError(null);

      const auth = getAuthSnapshot();
      if (!auth.serverUrl || !auth.isAuthenticated) {
        setIsLoading(false);
        return;
      }

      try {
        const headers = auth.accessToken ? { Authorization: `Bearer ${auth.accessToken}` } : {};

        const [statusRes, streamsRes] = await Promise.all([
          Axios.get<{ data: QoLStatus }>(`${auth.serverUrl}/api/admin/qol/status`, { headers }),
          Axios.get<{ data: QoLStream[] }>(`${auth.serverUrl}/api/admin/qol/streams`, { headers }),
        ]);

        if (!cancelled) {
          setStatus(statusRes.data.data);
          setStreams(streamsRes.data.data);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err : new Error('Failed to fetch QoL status'));
        }
      } finally {
        if (!cancelled) {
          setIsLoading(false);
        }
      }
    }

    fetch();
    return () => { cancelled = true; };
  }, [key]);

  return { status, streams, isLoading, error, refetch: () => setKey(k => k + 1) };
}
