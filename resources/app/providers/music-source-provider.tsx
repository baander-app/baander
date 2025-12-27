import React, { RefObject, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { noop } from '@/app/utils/noop.ts';
import { usePlayerCurrentSong, usePlayerActions } from '@/app/modules/library-music-player/store';
import { Token } from '@/app/services/auth/token.ts';

interface MusicSourceContextType {
  authenticatedSource: string | undefined;
  audioRef: RefObject<HTMLAudioElement | null> | undefined;
  setAudioRef: (audioRef: RefObject<HTMLAudioElement | null>) => void;
}

export const MusicSourceContext = React.createContext<MusicSourceContextType>({
  authenticatedSource: undefined,
  audioRef: undefined,
  setAudioRef: () => noop(),
});
MusicSourceContext.displayName = 'MusicSourceContext';

export function MusicSourceProvider({ children }: { children: React.ReactNode }) {
  const currentSong = usePlayerCurrentSong();
  const { playNext } = usePlayerActions();
  const [audioRef, setAudioRef] = useState<RefObject<HTMLAudioElement | null>>();

  const authenticatedSource = useMemo(() => {
    const token = Token.get()?.access_token;
    if (currentSong?.streamUrl && token) {
      return `${currentSong.streamUrl}?_token=${token}`;
    }
    return undefined;
  }, [currentSong?.streamUrl]);

  const onSongEnd = useCallback(() => {
    playNext();
  }, [playNext]);

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
