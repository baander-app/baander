import { ReactEventHandler, useEffect, useRef, useState } from 'react';
import { Flex, Grid, Group, Text } from '@mantine/core';
import styles from './inline-player.module.scss';
import { noop } from '@/support/noop.ts';
import { Cover } from '@/features/library-music/components/artwork/cover';
import {
  NextButton,
  PauseButton,
  PlayButton,
  PreviousButton,
} from '@/features/library-music-player/components/player-controls/player-controls.tsx';
import { useMusicSource } from '@/providers';
import { Token } from '@/services/auth/token.ts';
import { ProgressBar } from '@/features/library-music-player/components/progress-bar/progress-bar.tsx';
import { formatDuration } from '@/support/time';
import { useStreamToken } from '@/hooks/use-stream-token.ts';

export function InlinePlayer() {
  const musicSource = useMusicSource();
  const { streamToken } = useStreamToken();

  const [isPlaying, setIsPlaying] = useState(false);
  const [duration, setDuration] = useState(0);
  const [isReady, setIsReady] = useState(false);
  const [currentProgress, setCurrentProgress] = useState<number>(0);
  const [buffered, setBuffered] = useState(0);

  const audioRef = useRef<HTMLAudioElement>(new Audio());
  const intervalRef = useRef();

  const togglePlayPause = () => {
    if (isPlaying) {
      audioRef.current.pause();
      setIsPlaying(false);
    } else if (isReady) {
      audioRef.current.play();
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

    console.log('timeupdate', audio.currentTime);

    setCurrentProgress(audio.currentTime);
    handleBufferProgress(e);
  };

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
    if (!musicSource.source) {
      return;
    }

    audioRef.current.pause();
    audioRef.current = new Audio(`${musicSource.source}?_token=${streamToken}`);
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

  return (
    <Grid>
      <Grid.Col mt="6px" span={2}>
        <Flex align="center" ml="sm">
          <Cover imgSrc={musicSource.details?.coverUrl} size={72}/>

          <Text ml="sm">{musicSource.details?.title}</Text>
        </Flex>
      </Grid.Col>

      <Grid.Col span={8} mt="10px">
        <Flex className={styles.controls} direction="column" justify="center">
          <Flex justify="center">
            <Flex align="center" w="70%">
              <Text pr="sm">{elapsedDisplay}</Text>

              <ProgressBar
                duration={duration}
                currentProgress={currentProgress}
                buffered={buffered}

                setProgress={(e) => {
                  console.log(e);

                  if (!audioRef.current) return;

                  audioRef.current.currentTime = e;

                  setCurrentProgress(e);
                }}
              />

              <Text pl="sm">{durationDisplay}</Text>
            </Flex>
          </Flex>

          <Group className={styles.buttonGroup} justify="center">
            <PreviousButton onClick={noop}/>

            {isPlaying
              ? <PauseButton onClick={() => togglePlayPause()}/>
              : <PlayButton onClick={() => togglePlayPause()}/>
            }

            <NextButton onClick={noop}/>
          </Group>
        </Flex>
      </Grid.Col>
    </Grid>
  );
}

