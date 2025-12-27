import { useState, useCallback } from 'react';
import { usePlayerQueue, usePlayerActions } from '@/app/modules/library-music-player/store';
import { SongResource } from '@/app/libs/api-client/gen/models';

export function useQueue() {
  const queue = usePlayerQueue();
  const { setQueue, playSongAtIndex } = usePlayerActions();
  const [activeIndex, setActiveIndex] = useState(0);

  const overwriteQueue = useCallback((newQueue: SongResource[]) => {
    setQueue(newQueue);
    setActiveIndex(0);
  }, [setQueue]);

  const playSongAtIndexHandler = useCallback((index: number) => {
    setActiveIndex(index);
    playSongAtIndex(index);
  }, [playSongAtIndex]);

  return {
    queue,
    activeIndex,
    overwriteQueue,
    playSongAtIndex: playSongAtIndexHandler,
  };
}