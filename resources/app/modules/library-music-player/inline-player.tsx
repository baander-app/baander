import { useEffect, useMemo, useRef, useState } from 'react';
import { Flex } from '@radix-ui/themes';
import { PlayerControls } from '@/modules/library-music-player/components/player-controls/player-controls.tsx';
import PlayerFacePlate from '@/modules/library-music-player/components/player-face-plate/player-face-plate.tsx';
import {
  PlayerMetaControls,
} from '@/modules/library-music-player/components/player-meta-controls/player-meta-controls.tsx';
import { LyricsProvider } from '@/ui/lyrics-viewer/providers/lyrics-provider.tsx';
import { selectSong } from '@/store/music/music-player-slice.ts';
import { useAppSelector } from '@/store/hooks.ts';
import {
  attachAudioElement,
  usePlayerActions,
  usePlayerBuffered,
  usePlayerCurrentTime,
  usePlayerDuration,
  usePlayerIsPlaying,
  usePlayerAudioElement,
} from '@/modules/library-music-player/store';
import { useShowByPublicIdSong } from '@/libs/api-client/gen/endpoints/library-resource/library-resource.ts';
import { ensureStreamToken } from '@/services/auth/ensure-stream-token.ts';
import { isElectron } from '@/utils/platform.ts';

export function InlinePlayer() {
  const sourceSong = useAppSelector(selectSong);
  const buffered = usePlayerBuffered();
  const duration = usePlayerDuration();
  const isPlaying = usePlayerIsPlaying();
  const currentTime = usePlayerCurrentTime();
  const existingAudioElement = usePlayerAudioElement();
  const cleanupRef = useRef<(() => void) | null>(null);
  const hasInitialized = useRef(false);
  const currentSong = useAppSelector(selectSong);
  const [currentStreamUrl, setCurrentStreamUrl] = useState<string | null>(null);
  const {
    setSong,
    seekTo,
    togglePlayPause,
  } = usePlayerActions();
  const canQuery = Boolean(sourceSong?.publicId);
  const { data: song } = useShowByPublicIdSong(sourceSong?.publicId!, {
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
      ensureStreamToken().then(token => {
        if (token) {
          const url = `${currentSong.streamUrl}?_token=${token}`;
          setCurrentStreamUrl(url);
        } else {
          setCurrentStreamUrl(null);
        }
      });
    } else {
      setCurrentStreamUrl(null);
    }
  }, [currentSong?.streamUrl]);

  useEffect(() => {
    const audioElement = existingAudioElement;
    if (audioElement && currentStreamUrl) {
      audioElement.src = currentStreamUrl;
      audioElement.play();
    } else if (audioElement && !currentStreamUrl) {
      audioElement.src = '';
      audioElement.pause();
    }
  }, [currentStreamUrl, existingAudioElement]);

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
  }, [song]);

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
