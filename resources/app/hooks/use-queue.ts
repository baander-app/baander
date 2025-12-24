import { useState, useCallback } from 'react';
import { useAppDispatch, useAppSelector } from '@app/store/hooks';
import { setQueue, setCurrentSongIndex } from '@app/store/music/music-player-slice';
import { SongResource } from '@app/libs/api-client/gen/models';

export function useQueue() {
  const dispatch = useAppDispatch();
  const queue = useAppSelector((state) => state.musicPlayer.queue);
  const [activeIndex, setActiveIndex] = useState(0);

  const overwriteQueue = useCallback((newQueue: SongResource[]) => {
    dispatch(setQueue(newQueue));
    setActiveIndex(0);
  }, [dispatch]);

  const playSongAtIndex = useCallback((index: number) => {
    setActiveIndex(index);
    dispatch(setCurrentSongIndex(index));
  }, [dispatch]);

  return {
    queue,
    activeIndex,
    overwriteQueue,
    playSongAtIndex,
  };
}