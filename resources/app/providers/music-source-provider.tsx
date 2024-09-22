import React, { MutableRefObject, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { noop } from '@/utils/noop.ts';
import { useStreamToken } from '@/hooks/use-stream-token.ts';
import { useAppDispatch, useAppSelector } from '@/store/hooks.ts';
import { playNextSong, selectSong } from '@/store/music/music-player-slice.ts';

interface MusicSourceContextType {
  authenticatedSource: string | undefined;
  audioRef: MutableRefObject<HTMLAudioElement | null> | undefined;
  setAudioRef: (audioRef: MutableRefObject<HTMLAudioElement | null>) => void;
}

export const MusicSourceContext = React.createContext<MusicSourceContextType>({
  authenticatedSource: undefined,
  audioRef: undefined,
  setAudioRef: () => noop(),
});
MusicSourceContext.displayName = 'MusicSourceContext';

export function MusicSourceProvider({ children }: { children: React.ReactNode }) {
  const dispatch = useAppDispatch();
  const { streamToken } = useStreamToken();

  const currentSong = useAppSelector(selectSong);
  const [audioRef, setAudioRef] = useState<MutableRefObject<HTMLAudioElement | null>>();

  const authenticatedSource = useMemo(() => {
    if (currentSong?.stream && streamToken) {
      return `${currentSong.stream}?_token=${streamToken}`;
    }
    return undefined;
  }, [currentSong, streamToken]);

  const onSongEnd = useCallback(() => {
    dispatch(playNextSong());
  }, [dispatch]);

  useEffect(() => {
    if (audioRef?.current) {
      audioRef.current.addEventListener('ended', onSongEnd);
      return () => audioRef.current?.removeEventListener('ended', onSongEnd);
    }
  }, [audioRef, onSongEnd]);

  return (
    <MusicSourceContext.Provider
      value={{
        authenticatedSource,
        audioRef,
        setAudioRef,
      }}
    >
      {children}
    </MusicSourceContext.Provider>
  );
}

export function useMusicSource() {
  return useContext(MusicSourceContext);
}