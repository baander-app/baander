import { useEffect, useMemo } from 'react';
import { Flex } from '@radix-ui/themes';
import { useMusicSource } from '@/providers/music-source-provider.tsx';
import { PlayerStateInput } from '@/services/libraries/player-state.ts';
import { PlayerControls } from '@/modules/library-music-player/components/player-controls/player-controls.tsx';
import PlayerFacePlate from '@/modules/library-music-player/components/player-face-plate/player-face-plate.tsx';
import {
  PlayerMetaControls,
} from '@/modules/library-music-player/components/player-meta-controls/player-meta-controls.tsx';
import { LyricsProvider } from '@/ui/lyrics-viewer/providers/lyrics-provider.tsx';
import { selectSong } from '@/store/music/music-player-slice.ts';
import { useAppSelector } from '@/store/hooks.ts';
import { useSongsShow } from '@/libs/api-client/gen/endpoints/song/song.ts';
import {
  usePlayerActions, usePlayerAudioElement, usePlayerBuffered,
  usePlayerCurrentTime,
  usePlayerDuration,
  usePlayerIsPlaying,
} from '@/modules/library-music-player/store';

export function InlinePlayer() {
  const sourceSong = useAppSelector(selectSong);
  const buffered = usePlayerBuffered();
  const duration = usePlayerDuration();
  const isPlaying = usePlayerIsPlaying();
  const currentTime = usePlayerCurrentTime();
  const audioElement = usePlayerAudioElement();

  const {
    authenticatedSource,
  } = useMusicSource();

  const canQuery = Boolean(sourceSong?.publicId);
  const { data: song } = useSongsShow('music', sourceSong?.publicId!, undefined, {
    query: {
      enabled: canQuery,
    }
  });

  const { setSong } = usePlayerActions();

  useEffect(() => {
    if (song) {
      setSong(song);
    } else {
      setSong(null);
    }
  }, [song]);

  const {
    seekTo,
    togglePlayPause
  } = usePlayerActions();

  useEffect(() => {
    let timerId = setInterval(() => {
      if (!authenticatedSource) {
        return;
      }
// @ts-expect-error
      const data: PlayerStateInput = {
        isPlaying,
        volumePercent: 100,
        progressMs: currentTime * 1000,
      };
    }, 5000);

    return () => {
      clearInterval(timerId);
    };
  }, []);

  const setProgress = (e: number) => {
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

