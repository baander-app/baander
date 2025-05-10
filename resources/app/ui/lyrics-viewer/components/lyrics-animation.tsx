import React, { useEffect, useState } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { useLyrics } from '@/ui/lyrics-viewer/providers/lyrics-provider.tsx';
import { Box, Text } from '@radix-ui/themes';
import styles from './lyrics-animation.module.scss';

interface LyricsAnimationProps {
  currentTime: number;
}

export const LyricsAnimation: React.FC<LyricsAnimationProps> = ({ currentTime }) => {
  const { synchronizer } = useLyrics();

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
    <Box width="inherit" className={styles.lyricContainer}>
      <AnimatePresence mode="sync">
        {currentLyric && (
          <Text
            // @ts-expect-error
            as={motion.p}
            style={{
              color: '#fff',
              fontSize: '32px',
              fontWeight: 700
            }}
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
      {nextLyric && (
        <Text
          // @ts-expect-error
          as={motion.p}
          style={{
            color: '#fff',
            fontSize: '24px',
          }}
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