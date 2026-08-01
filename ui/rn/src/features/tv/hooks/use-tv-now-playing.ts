/**
 * use-tv-now-playing -- hook for now-playing overlay visibility.
 *
 * Per user decision: overlay stays visible during navigation and animates with page transitions.
 * Overlay renders over TVNavigator, not individual pages.
 */

import { useEffect, useState } from 'react';
import { usePlayerStore } from '@/features/player/stores/player-store';

export interface UseTVNowPlayingResult {
  isVisible: boolean;
  currentTrack: ReturnType<typeof usePlayerStore>['currentTrack'] | null;
  isPlaying: boolean;
  progress: number;
  dismiss: () => void;
}

export function useTVNowPlaying(): UseTVNowPlayingResult {
  const { currentTrack, isPlaying } = usePlayerStore(
    useShallow((s) => ({ currentTrack: s.currentTrack, isPlaying: s.isPlaying })),
  );
  const [isVisible, setIsVisible] = useState(false);
  const [progress, setProgress] = useState(0);

  // Show overlay when playing
  useEffect(() => {
    if (currentTrack && isPlaying) {
      setIsVisible(true);
    }
  }, [currentTrack, isPlaying]);

  // Progress simulation (replace with real progress from player)
  useEffect(() => {
    if (!isPlaying || !currentTrack) {
      return;
    }

    const interval = setInterval(() => {
      setProgress((prev) => {
        const next = prev + 1;
        return next > 100 ? 0 : next;
      });
    }, 1000);

    return () => clearInterval(interval);
  }, [isPlaying, currentTrack]);

  return {
    isVisible: isVisible && !!currentTrack,
    currentTrack,
    isPlaying,
    progress,
    dismiss: () => setIsVisible(false),
  };
}

import { useShallow } from 'zustand/react/shallow';
