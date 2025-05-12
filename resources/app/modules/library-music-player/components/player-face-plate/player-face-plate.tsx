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
    <Flex flexGrow="2" align="center">
      <Cover imgSrc={viewModel.coverUrl} size={64} />

      <Flex
        direction="column"
        width="100%"
        ml="3"
      >
        <Flex
          direction="column"
          justify="center"
          align="center"
        >
          <Text weight="bold" size="2">
            {viewModel?.title}
          </Text>

          <Text size="1">
            {viewModel.album ? `${viewModel.album}` : ''}
            {artistNames ? ` | ${artistNames}` : ''}
          </Text>
        </Flex>

        <Flex
          direction="row"
          className={styles.progressContainer}
          align="center"
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
    </Flex>
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