import React, { createContext, ReactEventHandler, ReactNode, useContext, useEffect, useState } from 'react';
import { Lrc } from '@/libs/lyrics/lrc.ts';
import { AudioLyricSynchronizer } from '@/libs/lyrics/audio-lyric-synchronizer.ts';
import { useAudioPlayer } from '@/modules/library-music-player/providers/audio-player-provider.tsx';

interface LyricsProviderContextProps {
  lyrics: Lrc | undefined;
  synchronizer: AudioLyricSynchronizer | undefined;
  setLyrics: (lyrics?: string | undefined) => void;
}

const LyricsProviderContext = createContext<LyricsProviderContextProps | undefined>(undefined);
LyricsProviderContext.displayName = 'LyricsProviderContext';

const LyricsProvider: React.FC<{ children: ReactNode }> = ({ children }) => {
  const [lyrics, _setLyrics] = useState<Lrc>();
  const {song} = useAudioPlayer();
  const audioLyricsSynchronizer = new AudioLyricSynchronizer();

  const { audioRef } = useAudioPlayer();

  useEffect(() => {
    if (song?.lyrics) {
     setLyrics(song.lyrics);
    }
  }, [song, song?.lyrics]);

  useEffect(() => {
    const handler: ReactEventHandler<HTMLAudioElement> = () => {
      audioLyricsSynchronizer.timeUpdate(audioRef.current.currentTime);
    }

    const audioRefCurrent = audioRef.current;
    // @ts-ignore
    audioRefCurrent.addEventListener('timeupdate', handler);

    return () => {
      // @ts-ignore
      audioRefCurrent.removeEventListener('timeupdate', handler);
    };
  }, []);

  const setLyrics = (value?: string | undefined) => {
    if (!value) {
      _setLyrics(undefined);

      return;
    }

    const lrc = Lrc.parse(value);
    _setLyrics(lrc);
    audioLyricsSynchronizer.setLrc(lrc);
  };

  return (
    <LyricsProviderContext.Provider value={{
      lyrics,
      setLyrics: setLyrics,
      synchronizer: audioLyricsSynchronizer,
    }}>
      {children}
    </LyricsProviderContext.Provider>
  );
};
LyricsProvider.displayName = 'LyricsProvider';

const useLyrics = () => {
  const context = useContext(LyricsProviderContext);

  if (!context) {
    throw new Error('useLyrics must be used within a LyricsProvider');
  }

  return context;
};

export {
  LyricsProvider,
  useLyrics,
};