import React from 'react';
import { Cover } from '@/modules/library-music/components/artwork/cover';
import styles from './album.module.scss';
import { Box, Text } from '@mantine/core';

interface AlbumProps extends Omit<React.HTMLAttributes<HTMLDivElement>, 'className'> {
  title: string;
  primaryArtist?: string;
  imgSrc?: string;
}

export function Album({ title, primaryArtist, imgSrc, ...props }: AlbumProps) {

  return (
    <Box p="3px" className={styles.album} {...props}>
      <Cover imgSrc={imgSrc} size={160} interactive={true} />

      <Text size="sm" className={styles.title}>{ title }</Text>
      {primaryArtist && (
        <Text size="sm">{ primaryArtist }</Text>
      )}
    </Box>
  )
}
