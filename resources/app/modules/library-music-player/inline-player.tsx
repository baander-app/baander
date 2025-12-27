import { useEffect, useMemo, useRef, useState } from 'react';
import { Flex } from '@radix-ui/themes';
import { PlayerControls } from '@/app/modules/library-music-player/components/player-controls/player-controls.tsx';
import PlayerFacePlate from '@/app/modules/library-music-player/components/player-face-plate/player-face-plate.tsx';
import {
  PlayerMetaControls,
} from '@/app/modules/library-music-player/components/player-meta-controls/player-meta-controls.tsx';
import { LyricsProvider } from '@/app/ui/lyrics-viewer/providers/lyrics-provider.tsx';
import {
  attachAudioElement,
  usePlayerActions,
  usePlayerCurrentSong,
  usePlayerBuffered,
  usePlayerCurrentTime,
  usePlayerDuration,
  usePlayerIsPlaying,
  usePlayerAudioElement,
} from '@/app/modules/library-music-player/store';
import { isElectron } from '@/app/utils/platform.ts';
import { Token } from '@/app/services/auth/token.ts';
import { useSongsShow } from '@/app/libs/api-client/gen/endpoints/song/song.ts';

export function InlinePlayer() {
  const sourceSong = usePlayerCurrentSong();
  const buffered = usePlayerBuffered();
  const duration = usePlayerDuration();
  const isPlaying = usePlayerIsPlaying();
  const currentTime = usePlayerCurrentTime();
  const existingAudioElement = usePlayerAudioElement();
  const cleanupRef = useRef<(() => void) | null>(null);
  const hasInitialized = useRef(false);
  const currentSong = usePlayerCurrentSong();
  const [currentStreamUrl, setCurrentStreamUrl] = useState<string | null>(null);
  const {
    setSong,
    seekTo,
    togglePlayPause,
    setSource,
  } = usePlayerActions();
  const canQuery = Boolean(sourceSong?.publicId && sourceSong?.librarySlug);
  const { data: song } = useSongsShow(sourceSong?.librarySlug!, sourceSong?.publicId!, {
    relations: 'album.cover'
  }, {
    query: {
      enabled: canQuery,
    },
  });

  useEffect(() => {
    if (!existingAudioElement && !hasInitialized.current) {
      hasInitialized.current = true;

      const audioElement = new Audio();
      if (isElectron()) {
        audioElement.crossOrigin = 'anonymous';
      }

      cleanupRef.current = attachAudioElement(audioElement);
    }

    return () => {
      if (cleanupRef.current) {
        cleanupRef.current();
        cleanupRef.current = null;
      }
      hasInitialized.current = false;
    };
  }, []);

  useEffect(() => {
    if (currentSong?.streamUrl) {
      const token = Token.get()?.access_token;
      if (token) {
        const url = `${currentSong.streamUrl}?_token=${token}`;
        setCurrentStreamUrl(url);
      } else {
        setCurrentStreamUrl(null);
      }
    } else {
      setCurrentStreamUrl(null);
    }
  }, [currentSong?.streamUrl]);

  useEffect(() => {
    const audioElement = existingAudioElement;
    if (audioElement && currentStreamUrl) {
      setSource(currentStreamUrl);
      audioElement.play();
    } else if (audioElement && !currentStreamUrl) {
      setSource(null);
      audioElement.pause();
    }
  }, [currentStreamUrl, existingAudioElement, setSource]);

  useEffect(() => {
    if (song) {
      setSong(song);
    } else {
      setSong(null);
    }
  }, [song, setSong]);

  const setProgress = (e: number) => {
    const audioElement = existingAudioElement;
    if (!audioElement) return;

    audioElement.currentTime = e;
    seekTo(e);
  };

  const coverUrl = useMemo(() => {
    return song?.album?.cover?.url ?? '';
  }, [song?.album?.cover?.url]);

  const artistNames = useMemo(() => {
    return song && song?.artists?.map(artist => artist.name);
  }, [song]);

  const title = useMemo(() => song && song.title, [song]);

  const album = useMemo(() => song && song.album, [song]);

  return (
    <>
      <LyricsProvider>
        <Flex justify="center" flexGrow="1" style={{ flexGrow: 1 }}>
          <PlayerControls
            isPlaying={isPlaying}
            togglePlayPause={() => togglePlayPause()}
          />

          <PlayerFacePlate
            buffered={buffered}
            duration={duration}
            currentProgress={currentTime}
            setProgress={progress => setProgress(progress)}
            viewModel={{
              coverUrl,
              title,
              artists: artistNames,
              album: album?.title,
            }}
          />
          <PlayerMetaControls song={song}/>
        </Flex>
      </LyricsProvider>
    </>
  );
}
