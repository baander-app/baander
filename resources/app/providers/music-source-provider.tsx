import React, { MutableRefObject, useContext, useMemo, useState } from 'react';
import { noop } from '@/utils/noop.ts';
import { useStreamToken } from '@/hooks/use-stream-token.ts';
import { SongResource } from '@/api-client/requests';

interface MusicSourceContextType {
  authenticatedSource: string | undefined;
  audioRef: MutableRefObject<HTMLAudioElement> | undefined;
  setAudioRef: (audioRef: MutableRefObject<HTMLAudioElement>) => void;
  song: SongResource | null;
  setSong: (song: SongResource | null) => void;
}

export const MusicSourceContext = React.createContext<MusicSourceContextType>({
  authenticatedSource: undefined,
  audioRef: undefined,
  setAudioRef: () => noop(),
  song: null,
  setSong: () => noop(),
});
MusicSourceContext.displayName = 'MusicSourceContext';

export function MusicSourceProvider({ children }: { children: React.ReactNode }) {
  const [audioRef, setAudioRef] = useState<MutableRefObject<HTMLAudioElement>>();

  const { streamToken } = useStreamToken();
  const [song, setSong] = useState<SongResource | null>(null);

  const authenticatedSource = useMemo(() => {
    if (song?.stream && streamToken) {
      return `${song.stream}?_token=${streamToken}`;
    }
  }, [song?.stream, streamToken]);


  return (
    <MusicSourceContext.Provider
      value={{
        authenticatedSource,
        audioRef,
        setAudioRef,
        song,
        setSong
      }}
    >{children}</MusicSourceContext.Provider>
  );
}

export function useMusicSource() {
  return useContext(MusicSourceContext);
}