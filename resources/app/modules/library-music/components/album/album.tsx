import React from 'react';
import { Cover } from '@/modules/library-music/components/artwork/cover';
import styles from './album.module.scss';
import { Box, Flex, Text } from '@radix-ui/themes';

interface AlbumProps extends Omit<React.HTMLAttributes<HTMLDivElement>, 'className'> {
  title: string;
  primaryArtist?: string;
  imgSrc?: string;
}

export function Album({ title, primaryArtist = "Unknown Artist", imgSrc, ...props }: AlbumProps) {
  return (
    <Box p="3px" className={styles.album} {...props}>
      <Cover imgSrc={imgSrc} size={160} interactive={true} />

      <Flex direction="column">
        <Text size="1" className={styles.title} title={title}>
          {title}
        </Text>
        <Text size="1" className={styles.artist} title={primaryArtist}>
          {primaryArtist}
        </Text>
      </Flex>
    </Box>
  );
}
