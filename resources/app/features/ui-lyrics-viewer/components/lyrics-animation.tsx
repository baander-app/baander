import React, { useEffect, useState } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { useLyrics } from '@/features/ui-lyrics-viewer/providers/lyrics-provider.tsx';
import { Box, Text, useMantineTheme } from '@mantine/core';
import styles from './lyrics-animation.module.scss';

interface LyricsAnimationProps {
  currentTime: number;
}

export const LyricsAnimation: React.FC<LyricsAnimationProps> = ({ currentTime }) => {
  const { synchronizer } = useLyrics();

  const theme = useMantineTheme();
  const [currentLyric, setCurrentLyric] = useState<string>('');
  const [nextLyric, setNextLyric] = useState<string>('');

  useEffect(() => {
    if (currentTime && synchronizer) {
      const current = synchronizer.current();
      setCurrentLyric(current?.content ?? '');

      const next = synchronizer.next();
      setNextLyric(next?.content ?? '');
    }
  }, [currentTime, synchronizer]);

  return (
    <Box w="inherit" className={styles.lyricContainer}>
      <AnimatePresence mode="sync">
        {currentLyric && ( // Only render if there is text
          <Text
            component={motion.p}
            fz={32}
            fw={700}
            c={theme.white}
            className={`${styles.lyricLine} ${styles.currentLyric}`}
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -20 }}
            transition={{ duration: 0.5 }}
            key={currentLyric}
          >
            {currentLyric}
          </Text>
        )}
      </AnimatePresence>
      {nextLyric && ( // Only render if there is text
        <Text
          component={motion.p}
          fz={24}
          c={theme.white}
          className={`${styles.lyricLine} ${styles.nextLyric}`}
          initial={{ opacity: 0, y: 40 }}
          animate={{ opacity: 0.5, y: 20 }}
          transition={{ duration: 0.5 }}
          key={nextLyric}
        >
          {nextLyric}
        </Text>
      )}
    </Box>
  );
};