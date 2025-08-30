import { motion } from 'motion/react';
import { Box, Flex, IconButton, Popover, Tooltip } from '@radix-ui/themes';
import { LyricsAnimation } from '@/ui/lyrics-viewer/components/lyrics-animation/lyrics-animation.tsx';
import { MixerHorizontalIcon } from '@radix-ui/react-icons';
import { LyricsSettings } from '@/ui/lyrics-viewer/components/lyrics-settings/lyrics-settings.tsx';
import styles from './lyrics-viewer.module.scss';
import { usePlayerCurrentTime, usePlayerSong } from '@/modules/library-music-player/store';
import { useSongsShow } from '@/libs/api-client/gen/endpoints/song/song.ts';

const AnimatedBackground = motion.div;

export function LyricsViewer() {
  const currentTime = usePlayerCurrentTime();
  const playerSong = usePlayerSong();

  const { data } = useSongsShow(playerSong!.libraryId, playerSong!.publicId, { relations: 'album.cover' }, {
    query: {
      enabled: Boolean(playerSong?.libraryId && playerSong.publicId),
    },
  });

  const backgroundVariants = {
    initial: {
      filter: 'blur(0px)',
    },
    animate: {
      filter: 'blur(16px)',
      transition: {
        duration: 1,
      },
    },
  };

  return (
    <Box className={styles.lyricsContainer}>
      <AnimatedBackground
        className={styles.background}
        initial="initial"
        animate="animate"
        layout
        variants={backgroundVariants}
        style={{
          backgroundImage: `url(${data?.album?.cover?.url ?? ''})`,
          backgroundSize: 'cover',
          backgroundPosition: 'center',
        }}
      />

      <div className={styles.overlay}/>

      <LyricsAnimation currentTime={currentTime}/>

      <Flex className={styles.footer}>
        <Popover.Root>
          <Popover.Trigger>
            <Tooltip content="Lyrics settings">
              <IconButton style={{ cursor: 'pointer' }}>
                <MixerHorizontalIcon/>
              </IconButton>
            </Tooltip>
          </Popover.Trigger>

          <Popover.Content>
            <LyricsSettings/>
          </Popover.Content>
        </Popover.Root>
      </Flex>
    </Box>
  );
}
