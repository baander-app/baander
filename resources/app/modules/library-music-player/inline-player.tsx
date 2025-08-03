import { useEffect, useMemo } from 'react';
import { Flex } from '@radix-ui/themes';
import { useMusicSource } from '@/providers/music-source-provider.tsx';
import { PlayerStateInput } from '@/services/libraries/player-state.ts';
import { useAudioPlayer } from '@/modules/library-music-player/providers/audio-player-provider.tsx';
import { PlayerControls } from '@/modules/library-music-player/components/player-controls/player-controls.tsx';
import PlayerFacePlate from '@/modules/library-music-player/components/player-face-plate/player-face-plate.tsx';
import {
  PlayerMetaControls,
} from '@/modules/library-music-player/components/player-meta-controls/player-meta-controls.tsx';
import { LyricsProvider } from '@/ui/lyrics-viewer/providers/lyrics-provider.tsx';
import { selectSong } from '@/store/music/music-player-slice.ts';
import { useAppSelector } from '@/store/hooks.ts';
import { useSongsShow } from '@/libs/api-client/gen/endpoints/song/song.ts';

export function InlinePlayer() {
  const sourceSong = useAppSelector(selectSong);

  useEffect(() => {
    console.log('sourceSong', sourceSong);
  }, [sourceSong]);

  const {
    authenticatedSource,
  } = useMusicSource();

  const canQuery = Boolean(sourceSong?.public_id);
  const { data: song } = useSongsShow('music', sourceSong?.public_id!, {

  });

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
// @ts-expect-error
      const data: PlayerStateInput = {
        isPlaying,
        volumePercent: 100,
        progressMs: currentProgress,
      };
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
        </Flex>
      </LyricsProvider>

    </>
  );
}

