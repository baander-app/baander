/**
 * useSession -- hook for session state and queue sync.
 *
 * Syncs local player queue to server periodically and on changes.
 */

import { useState, useEffect, useCallback, useRef } from 'react';
import type { Session, SessionTrack } from '../api/session-api';
import { getSession, syncSession } from '../api/session-api';
import { usePlayerStore } from '@/features/player/stores/player-store';

export function useSession() {
  const [session, setSession] = useState<Session | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);
  const queue = usePlayerStore((s) => s.queue);
  const syncTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Fetch initial session
  useEffect(() => {
    let cancelled = false;

    async function fetch() {
      setIsLoading(true);
      setError(null);
      try {
        const result = await getSession();
        if (!cancelled) setSession(result);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err : new Error('Failed to fetch session'));
        }
      } finally {
        if (!cancelled) setIsLoading(false);
      }
    }

    fetch();
    return () => { cancelled = true; };
  }, []);

  // Debounced queue sync (5s after last change)
  const syncQueue = useCallback(async () => {
    if (syncTimeoutRef.current) {
      clearTimeout(syncTimeoutRef.current);
    }

    syncTimeoutRef.current = setTimeout(async () => {
      try {
        const sessionTracks: SessionTrack[] = queue.map((track, index) => ({
          publicId: track.publicId,
          title: track.title,
          artistName: track.artistName,
          albumName: track.albumName,
          albumPublicId: track.albumPublicId,
          duration: track.duration,
          position: index,
        }));
        const result = await syncSession(sessionTracks);
        setSession(result);
      } catch {
        // Silently fail -- queue sync is best-effort
      }
    }, 5000);
  }, [queue]);

  // Trigger sync when queue changes
  useEffect(() => {
    if (queue.length > 0) {
      syncQueue();
    }
    return () => {
      if (syncTimeoutRef.current) {
        clearTimeout(syncTimeoutRef.current);
      }
    };
  }, [queue, syncQueue]);

  return {
    session,
    isLoading,
    error,
  };
}
