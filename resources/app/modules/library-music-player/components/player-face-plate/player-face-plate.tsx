import { Flex, Grid, Text } from '@mantine/core';
import { ProgressBar } from '@/modules/library-music-player/components/progress-bar/progress-bar.tsx';
import { formatDuration } from '@/utils/time/format-duration.ts';
import { SongResource } from '@/api-client/requests';
import { Cover } from '@/modules/library-music/components/artwork/cover';

import styles from './player-face-plate.module.scss';

export interface PlayerFacePlateProps {
  buffered: number;
  duration: number;
  currentProgress: number;
  setProgress: (progress: number) => void;
  song?: SongResource;
}

export function PlayerFacePlate({
                                  buffered,
                                  duration,
                                  currentProgress,
                                  setProgress,
                                  song,
                                }: PlayerFacePlateProps) {
  const durationDisplay = formatDuration(duration);
  const elapsedDisplay = formatDuration(currentProgress);

  const artistNames = song && song?.artists?.map(artist => artist.name);

  return (
    <Grid className={styles.facePlateGrid} gutter={0} style={{ '--grid-margin': 'unset' }} bg="gray.1">
      <Grid.Col span={1}>
        <Cover imgSrc={song?.album?.coverUrl} size={72}/>
      </Grid.Col>

      <Grid.Col span={11} pb="xs" pr="xs">
        <Flex direction="column" className={styles.innerContainer} >
          <Flex justify="center" align="center" direction="column">
            <Text fw="700" fz="sm" className={styles.trackTitle}>{song?.title ?? ''}</Text>

            <Flex>
              {song?.album?.title && (
                <Text fz="xs" className={styles.trackTitle}>{song?.album?.title ?? ''}</Text>
              )}
              {artistNames && artistNames.length > 0 ? (
                <Text fz="xs">&nbsp;| {artistNames}</Text>
              ) : <Text>&nbsp;</Text>}
            </Flex>

          </Flex>

          <Flex direction="row" className={styles.progressContainer}>
            <Text fz="xs" pr="xs">{elapsedDisplay}</Text>

            <ProgressBar
              className={styles.progressBar}
              duration={duration}
              currentProgress={currentProgress}
              buffered={buffered}
              setProgress={(e) => setProgress(e)}
            />

            <Text fz="xs" pl="xs">{durationDisplay}</Text>
          </Flex>
        </Flex>
      </Grid.Col>
    </Grid>
  );
}