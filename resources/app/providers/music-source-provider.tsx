import React, { MutableRefObject, useContext, useMemo, useState } from 'react';
import { noop } from '@/support/noop.ts';
import { useStreamToken } from '@/hooks/use-stream-token.ts';

interface SongDetails {
  coverUrl?: string;
  title: string;
}

interface MusicSourceContextType {
  authenticatedSource: string | null;
  audioRef: MutableRefObject<HTMLAudioElement> | null;
  setAudioRef: (audioRef: MutableRefObject<HTMLAudioElement> | null) => void;
  source: string | null;
  setSource: (source: string | null) => void;
  details: SongDetails | null;
  setDetails: (details: SongDetails) => void;
}

export const MusicSourceContext = React.createContext<MusicSourceContextType>({
  authenticatedSource: null,
  audioRef: null,
  setAudioRef: () => noop(),
  source: null,
  setSource: () => noop(),
  details: null,
  setDetails: () => noop(),
});
MusicSourceContext.displayName = 'MusicSourceContext';

export function MusicSourceProvider({ children }: { children: React.ReactNode }) {
  const [audioRef, setAudioRef] = useState<MutableRefObject<HTMLAudioElement>>(null);

  const { streamToken } = useStreamToken();

  const [source, setSource] = useState<string | null>(null);
  const [details, setDetails] = useState<SongDetails | null>(null);

  const authenticatedSource = useMemo(() => {
    if (source && streamToken) {
      return `${source}?_token=${streamToken}`;
    }
  }, [source, streamToken]);


  return (
    <MusicSourceContext.Provider
      value={{
        authenticatedSource,
        audioRef,
        setAudioRef,
        source,
        setSource,
        details,
        setDetails,
      }}
    >{children}</MusicSourceContext.Provider>
  );
}

export function useMusicSource() {
  return useContext(MusicSourceContext);
}