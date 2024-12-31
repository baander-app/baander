import { useEffect } from 'react';
import { Grid } from '@mantine/core';
import { useMusicSource } from '@/providers/music-source-provider';
import { useEcho } from '@/providers/echo-provider.tsx';
import { PlayerStateInput } from '@/services/libraries/player-state.ts';
import { useAudioPlayer } from '@/modules/library-music-player/providers/audio-player-provider.tsx';
import { useSongServiceSongsShow } from '@/api-client/queries';
import { PlayerControls } from '@/modules/library-music-player/components/player-controls/player-controls.tsx';
import { PlayerFacePlate } from '@/modules/library-music-player/components/player-face-plate/player-face-plate.tsx';
import {
  PlayerMetaControls
} from '@/modules/library-music-player/components/player-meta-controls/player-meta-controls.tsx';
import { LyricsProvider } from '@/ui/lyrics-viewer/providers/lyrics-provider.tsx';
import { selectSong } from '@/store/music/music-player-slice.ts';
import { useAppSelector } from '@/store/hooks.ts';

export function InlinePlayer() {
  const echo = useEcho();
  const sourceSong = useAppSelector(selectSong);
  const {
    authenticatedSource,
  } = useMusicSource();

  const canQuery = Boolean(sourceSong && sourceSong?.librarySlug && sourceSong?.public_id);
  const { data: song } = useSongServiceSongsShow({
    library: sourceSong?.librarySlug!,
    publicId: sourceSong?.public_id!,
    relations: 'album,album.cover,artists',
  }, undefined, { enabled: canQuery });

  const { setSong } = useAudioPlayer();

  useEffect(() => {
    if (song) {
      setSong(song);
    } else {
      setSong(null);
    }
  }, [song]);

  const {
    audioRef,
    isPlaying,
    duration,
    currentProgress,
    setCurrentProgress,
    buffered,
    togglePlayPause,
  } = useAudioPlayer();

  useEffect(() => {
    let timerId = setInterval(() => {
      if (!authenticatedSource) {
        return;
      }

      const data: PlayerStateInput = {
        isPlaying,
        volumePercent: 100,
        progressMs: currentProgress,
      };

      echo.playerStateChannel?.whisper('playerState', data);
    }, 5000);

    return () => {
      clearInterval(timerId);
    };
  }, [echo]);

  const setProgress = (e: number) => {
    if (!audioRef.current) return;

    audioRef.current.currentTime = e;

    setCurrentProgress(e);
  };

  return (
    <>
      <LyricsProvider>
        <Grid style={{ '--grid-margin': 'unset' }}>
          <Grid.Col span={3}>
            <PlayerControls
              isPlaying={isPlaying}
              togglePlayPause={() => togglePlayPause()}
            />
          </Grid.Col>

          <Grid.Col span={6}>
            <PlayerFacePlate
              buffered={buffered}
              duration={duration}
              currentProgress={currentProgress}
              setProgress={progress => setProgress(progress)}
              song={song}
            />
          </Grid.Col>

          <Grid.Col span={3}>
            <PlayerMetaControls song={song} />
          </Grid.Col>
        </Grid>
      </LyricsProvider>
    </>
  );
}

