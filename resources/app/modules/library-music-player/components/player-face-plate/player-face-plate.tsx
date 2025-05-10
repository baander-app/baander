import { Flex, Grid, Text } from '@radix-ui/themes';
import { ProgressBar } from '@/modules/library-music-player/components/progress-bar/progress-bar.tsx';
import { formatDuration } from '@/utils/time/format-duration.ts';
import { Cover } from '@/modules/library-music/components/artwork/cover';
import styles from './player-face-plate.module.scss';
import { withTestMode } from '@/providers/test-mode-provider.tsx';

export interface PlayerFacePlateViewModel {
  coverUrl: string;
  title: string;
  artists: string[];
  artist: string;
  album: string;
}

export interface PlayerFacePlateProps {
  buffered: number;
  duration: number;
  currentProgress: number;
  setProgress: (progress: number) => void;
  viewModel: Partial<PlayerFacePlateViewModel>;
}

export function PlayerFacePlate({
                                  buffered,
                                  duration,
                                  currentProgress,
                                  setProgress,
                                  viewModel,
                                }: PlayerFacePlateProps) {
  const durationDisplay = formatDuration(duration);
  const elapsedDisplay = formatDuration(currentProgress);

  const artistNames = viewModel.artists?.join(', ');

  return (
    <Grid className={styles.facePlateGrid} columns="auto 1fr" gap="1">
      <Cover imgSrc={viewModel.coverUrl} size={100} />

      <Flex
        direction="column"
        className={styles.innerContainer}
        style={{ justifyContent: 'center', height: '100%' }}
      >
        <Flex
          direction="column"
          justify="center"
          align="center"
          style={{ flex: 1, textAlign: 'center' }}
        >
          <Text weight="bold" size="2" className={styles.trackTitle}>
            {viewModel?.title}
          </Text>

          <Text size="1" className={styles.trackDetails}>
            {viewModel.album ? `${viewModel.album}` : ''}
            {artistNames ? ` | ${artistNames}` : ''}
          </Text>
        </Flex>

        <Flex
          direction="row"
          className={styles.progressContainer}
          align="center"
          style={{ justifyContent: 'space-between', width: '100%' }}
        >
          <Text size="2" mr="2">
            {elapsedDisplay}
          </Text>

          <ProgressBar
            duration={duration}
            currentProgress={currentProgress}
            buffered={buffered}
            setProgress={setProgress}
          />

          <Text size="2" ml="2">
            {durationDisplay}
          </Text>
        </Flex>
      </Flex>
    </Grid>
  );
}

// Wrap the component with withTestMode and pass the viewModel type as a plain object
export default withTestMode(PlayerFacePlate, {
  coverUrl: '',
  title: '',
  artists: [''],
  artist: '',
  album: '',
});