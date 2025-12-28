import React, { createContext, ReactEventHandler, ReactNode, useContext, useEffect, useState } from 'react';
import { Lrc } from '@/app/libs/lyrics/lrc.ts';
import { AudioLyricSynchronizer } from '@/app/libs/lyrics/audio-lyric-synchronizer.ts';
import { usePlayerAudioElement, usePlayerSong, usePlayerLyricsOffset } from '@/app/modules/library-music-player/store';
import { useSongsShow } from '@/app/libs/api-client/gen/endpoints/song/song.ts';

interface LyricsProviderContextProps {
  lyrics: Lrc | undefined;
  synchronizer: AudioLyricSynchronizer | undefined;
  setLyrics: (lyrics?: string | undefined) => void;
}

const LyricsProviderContext = createContext<LyricsProviderContextProps | undefined>(undefined);
LyricsProviderContext.displayName = 'LyricsProviderContext';

const LyricsProvider: React.FC<{ children: ReactNode }> = ({ children }) => {
  const lyricsOffset = usePlayerLyricsOffset();

  const [lyrics, _setLyrics] = useState<Lrc>();
  const playerSong = usePlayerSong();

  const canQuery = Boolean(playerSong?.libraryId && playerSong.publicId)
  const { data } = useSongsShow(playerSong?.libraryId!, playerSong?.publicId!, undefined, {
    query: {
      enabled: canQuery,
    },
  });
  const audioLyricsSynchronizer = new AudioLyricSynchronizer();

  const audioElement = usePlayerAudioElement();

  useEffect(() => {
    if (lyricsOffset && audioLyricsSynchronizer.defaultOffset !== lyricsOffset) {
      audioLyricsSynchronizer.setDefaultOffset(lyricsOffset);
    }
  }, [lyricsOffset, audioLyricsSynchronizer]);

  useEffect(() => {
    if (data?.lyrics) {
      setLyrics(data.lyrics);
    }
  }, [data, data?.lyrics]);

  useEffect(() => {
    const handler = () => {
      if (!audioElement) {
        return;
      }
      audioLyricsSynchronizer.timeUpdate(audioElement.currentTime);
    };

    audioElement?.addEventListener('timeupdate', handler);

    return () => {
      audioElement?.removeEventListener('timeupdate', handler);
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
