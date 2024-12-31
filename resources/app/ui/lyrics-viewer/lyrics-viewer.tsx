import { motion } from 'framer-motion';
import { Box, useMantineTheme } from '@mantine/core';
import { LyricsAnimation } from '@/ui/lyrics-viewer/components/lyrics-animation.tsx';
import { useAudioPlayer } from '@/modules/library-music-player/providers/audio-player-provider.tsx';

import styles from './lyrics-viewer.module.scss';

const AnimatedBackground = motion.div;

export function LyricsViewer() {
  const { currentProgress } = useAudioPlayer();
  const { song } = useAudioPlayer();
  const theme = useMantineTheme();

  const backgroundVariants = {
    initial: {
      filter: 'blur(0px)',
    },
    animate: {
      filter: 'blur(16px)',
      transition: {
        duration: 2,
      },
    },
  };

  return (
    <Box
      color={theme.white}
      h={{ base: 400, lg: 600 }}
      w={{ base: 400, lg: 600 }}
      className={styles.lyricsContainer}
    >
      <AnimatedBackground
        className={styles.background}
        initial="initial"
        animate="animate"
        variants={backgroundVariants}
        style={{
          backgroundImage: `url(${song?.album?.coverUrl ?? ''})`,
          backgroundSize: 'cover',
          backgroundPosition: 'center',
        }}
      />

      <LyricsAnimation currentTime={currentProgress}/>
    </Box>
  );
}