import React, { useContext, useState } from 'react';
import { noop } from '@/support/noop.ts';

interface SongDetails {
  coverUrl?: string;
  title: string;
}

interface MusicSourceContextType {
  source: string | null;
  setSource: (source: string | null) => void;
  details: SongDetails | null;
  setDetails: (details: SongDetails) => void;
}

export const MusicSourceContext = React.createContext<MusicSourceContextType>({
  source: null,
  setSource: () => noop(),
  details: null,
  setDetails: () => noop(),
});
MusicSourceContext.displayName = 'MusicSourceContext';

export function MusicSourceProvider({children}: { children: React.ReactNode }) {
  const [source, setSource] = useState<string | null>(null);
  const [details, setDetails] = useState<SongDetails | null>(null);

  return (
    <MusicSourceContext.Provider
      value={{source, setSource, details, setDetails}}
    >{children}</MusicSourceContext.Provider>
  );
}

export function useMusicSource() {
  return useContext(MusicSourceContext);
}