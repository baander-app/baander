import { useEffect } from 'react';
import { Flex, Grid, Group, Text } from '@mantine/core';
import { noop } from '@/support/noop.ts';
import { Cover } from '@/features/library-music/components/artwork/cover';
import {
  LyricsButton,
  NextButton,
  PlayPauseButton,
  PreviousButton,
  VisualizerButton,
} from '@/features/library-music-player/components/player-controls/player-controls.tsx';
import { useMusicSource } from '@/providers';
import { ProgressBar } from '@/features/library-music-player/components/progress-bar/progress-bar.tsx';
import { formatDuration } from '@/support/time';
import { useEcho } from '@/providers/echo-provider.tsx';
import { PlayerStateInput } from '@/services/libraries/player-state.ts';
import { useDisclosure } from '@mantine/hooks';
import { Waveform } from '@/components/waveform/waveform.tsx';
import styles from './inline-player.module.scss';
import { useAudioPlayer } from '@/features/library-music-player/providers/audio-player-provider.tsx';

export function InlinePlayer() {
  const echo = useEcho();
  const musicSource = useMusicSource();
  const {
    audioRef,
    isPlaying,
    duration,
    currentProgress,
    setCurrentProgress,
    buffered,
    togglePlayPause,
  } = useAudioPlayer();
  const [showWaveform, waveformHandlers] = useDisclosure(false);

  const durationDisplay = formatDuration(duration);
  const elapsedDisplay = formatDuration(currentProgress);

  useEffect(() => {
    let timerId = setInterval(() => {
      if (!musicSource.authenticatedSource) {
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
                <Group>
                  <VisualizerButton
                    isActive={showWaveform}
                    onClick={() => waveformHandlers.toggle()}
                  />

                  <LyricsButton
                    onClick={() => {/* dummy */
                    }}
                  />
                </Group>
              </Grid.Col>

              <Grid.Col span={8}>
                <PreviousButton onClick={noop}/>

                <PlayPauseButton
                  isPlaying={isPlaying}
                  onClick={() => togglePlayPause()}
                />

                <NextButton onClick={noop}/>
              </Grid.Col>

            </Grid>
          </Flex>
        </Grid.Col>
      </Grid>
      {showWaveform && (
        <Waveform onClose={() => waveformHandlers.close()}/>
      )}
    </>
  );
}

