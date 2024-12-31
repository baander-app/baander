import { ReactEventHandler, useEffect, useRef, useState } from 'react';
import { Flex, Grid, Text } from '@mantine/core';
import { noop } from '@/support/noop.ts';
import { Cover } from '@/features/library-music/components/artwork/cover';
import {
  NextButton,
  PauseButton,
  PlayButton,
  PreviousButton, VisualizerButton,
} from '@/features/library-music-player/components/player-controls/player-controls.tsx';
import { useMusicSource } from '@/providers';
import { ProgressBar } from '@/features/library-music-player/components/progress-bar/progress-bar.tsx';
import { formatDuration } from '@/support/time';
import { useEcho } from '@/providers/echo-provider.tsx';
import { PlayerStateInput } from '@/services/libraries/player-state.ts';
import { useDisclosure } from '@mantine/hooks';
import { Waveform } from '@/components/waveform/waveform.tsx';
import styles from './inline-player.module.scss';

export function InlinePlayer() {
  const echo = useEcho();
  const musicSource = useMusicSource();

  const [isPlaying, setIsPlaying] = useState(false);
  const [duration, setDuration] = useState(0);
  const [isReady, setIsReady] = useState(false);
  const [currentProgress, setCurrentProgress] = useState<number>(0);
  const [buffered, setBuffered] = useState(0);
  const [showWaveform, waveformHandlers] = useDisclosure(false);

  const audioRef = useRef<HTMLAudioElement>(new Audio());
  const intervalRef = useRef();

  const togglePlayPause = () => {
    if (isPlaying) {
      setIsPlaying(false);
    } else if (isReady) {
      setIsPlaying(true);
    }
  };

  const handleBufferProgress: ReactEventHandler<HTMLAudioElement> = (e) => {
    const audio = e.currentTarget;
    const dur = audio.duration;
    if (dur > 0) {
      for (let i = 0; i < audio.buffered.length; i++) {
        if (
          audio.buffered.start(audio.buffered.length - 1 - i) < audio.currentTime
        ) {
          const bufferedLength = audio.buffered.end(
            audio.buffered.length - 1 - i,
          );
          setBuffered(bufferedLength);
          break;
        }
      }
    }
  };

  const handleTimeUpdate: ReactEventHandler<HTMLAudioElement> = (e) => {
    const audio = e.currentTarget;

    // console.log('timeupdate', audio.currentTime);

    setCurrentProgress(audio.currentTime);
    handleBufferProgress(e);
  };

  useEffect(() => {
    if (audioRef) {
      musicSource.setAudioRef(audioRef);
    }
  }, [audioRef, musicSource.setAudioRef]);

  useEffect(() => {
    if (isPlaying) {
      audioRef.current.play();
    } else {
      audioRef.current.pause();
    }
  }, [isPlaying]);

  useEffect(() => {
    // Pause and clean up on unmount
    return () => {
      audioRef.current.pause();
      clearInterval(intervalRef.current);
    };
  }, []);

  useEffect(() => {
    if (!musicSource.authenticatedSource) {
      return;
    }

    // const wavesurfer = WaveSurfer.create({
    //   container: '#waveform',
    //   waveColor: '#4F4A85',
    //   progressColor: '#383351',
    //   url: source,
    // })


    audioRef.current.pause();
    audioRef.current = new Audio(musicSource.authenticatedSource);
    audioRef.current.preload = 'auto';
    audioRef.current.oncanplay = () => setIsReady(true);
    // @ts-ignore
    audioRef.current.ondurationchange = (e) => setDuration(e.currentTarget.duration);
    // @ts-ignore
    audioRef.current.ontimeupdate = (e) => handleTimeUpdate(e);
    // @ts-ignore
    audioRef.current.onprogress = (e) => handleBufferProgress(e);

    audioRef.current.play();
    setIsPlaying(true);
  }, [musicSource, musicSource.source]);

  const durationDisplay = formatDuration(duration);
  const elapsedDisplay = formatDuration(currentProgress);

  useEffect(() => {
    let timerId = setInterval(() => {
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

  return (
    <>
      <Grid>
        <Grid.Col mt="6px" span={3}>
          <Flex align="center" ml="sm">
            <Cover imgSrc={musicSource.details?.coverUrl} size={72}/>

            <Text ml="sm" className={styles.trackTitle}>{musicSource.details?.title}</Text>
          </Flex>
        </Grid.Col>

        <Grid.Col span={8} mt="10px">
          <Flex className={styles.controls} direction="column" justify="center">
            <Flex align="center" w="70%">
              <Text pr="sm">{elapsedDisplay}</Text>

              <ProgressBar
                duration={duration}
                currentProgress={currentProgress}
                buffered={buffered}

                setProgress={(e) => {
                  if (!audioRef.current) return;

                  audioRef.current.currentTime = e;

                  setCurrentProgress(e);
                }}
              />

              <Text pl="sm">{durationDisplay}</Text>
            </Flex>

            <Grid grow>
              <Grid.Col span={2}>
                <VisualizerButton
                  style={{ marginTop: '3px' }}
                  isActive={showWaveform}
                  onClick={() => waveformHandlers.toggle()}/>
              </Grid.Col>

              <Grid.Col span={8}>
                <PreviousButton onClick={noop}/>

                {isPlaying
                 ? <PauseButton onClick={() => togglePlayPause()}/>
                 : <PlayButton onClick={() => togglePlayPause()}/>
                }

                <NextButton onClick={noop}/>
              </Grid.Col>

            </Grid>
          </Flex>
        </Grid.Col>
      </Grid>
      {showWaveform && (
        <Waveform />
      )}
    </>
  );
}

