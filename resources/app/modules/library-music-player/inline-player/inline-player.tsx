import { useEffect, useMemo } from 'react';
import { Grid } from '@radix-ui/themes';
import { useMusicSource } from '@/providers/music-source-provider';
// import { useEcho } from '@/providers/echo-provider.tsx';
import { PlayerStateInput } from '@/services/libraries/player-state.ts';
import { useAudioPlayer } from '@/modules/library-music-player/providers/audio-player-provider.tsx';
import { useSongServiceGetApiLibrariesByLibrarySongsByPublicId } from '@/api-client/queries';
import { PlayerControls } from '@/modules/library-music-player/components/player-controls/player-controls.tsx';
import PlayerFacePlate from '@/modules/library-music-player/components/player-face-plate/player-face-plate.tsx';
import {
  PlayerMetaControls,
} from '@/modules/library-music-player/components/player-meta-controls/player-meta-controls.tsx';
import { LyricsProvider } from '@/ui/lyrics-viewer/providers/lyrics-provider.tsx';
import { selectSong } from '@/store/music/music-player-slice.ts';
import { useAppSelector } from '@/store/hooks.ts';

export function InlinePlayer() {
  // const echo = useEcho();
  const sourceSong = useAppSelector(selectSong);
  const {
    authenticatedSource,
  } = useMusicSource();

  const canQuery = Boolean(sourceSong && sourceSong?.librarySlug && sourceSong?.public_id);
  const { data: song } = useSongServiceGetApiLibrariesByLibrarySongsByPublicId({
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

      // echo.playerStateChannel?.whisper('playerState', data);
    }, 5000);

    return () => {
      clearInterval(timerId);
    };
  }, []);

  const setProgress = (e: number) => {
    if (!audioRef.current) return;

    audioRef.current.currentTime = e;

    setCurrentProgress(e);
  };

  const coverUrl = useMemo(() => {
    return song?.album?.coverUrl;
  }, [song]);

  // const artist = useMemo(() => song && song.artists, [song]);

  const artistNames = useMemo(() => {
    return song && song?.artists?.map(artist => artist.name);
  }, [song]);

  const title = useMemo(() => song && song.title, [song]);

  const album = useMemo(() => song && song.album, [song]);

  return (
    <>
      <LyricsProvider>
        <Grid columns="3fr 3fr 3fr" gap="1" width="100%">
          <PlayerControls
            isPlaying={isPlaying}
            togglePlayPause={() => togglePlayPause()}
          />

          <PlayerFacePlate
            buffered={buffered}
            duration={duration}
            currentProgress={currentProgress}
            setProgress={progress => setProgress(progress)}
            viewModel={{
              coverUrl,
              title,
              artists: artistNames,
              album: album?.title,
            }}
          />

          <PlayerMetaControls song={song}/>
        </Grid>
      </LyricsProvider>
    </>
  );
}

