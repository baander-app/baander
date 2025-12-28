import { Iconify } from '@/app/ui/icons/iconify';
import { PlaylistCover } from '@/app/ui/covers/playlist-cover';
import { memo } from 'react';
import { Badge, Box, Text } from '@radix-ui/themes';
import { PlaylistResource } from '@/app/libs/api-client/gen/models';
import styles from './playlist-card.module.scss';

interface PlaylistCardProps {
  playlist: PlaylistResource;
  onClick?: () => void;
}

export const PlaylistCard = memo(({ playlist, onClick }: PlaylistCardProps) => {
  const isSmart = playlist.isSmart === "1";

  // Extract album covers from songs for mosaic
  const albumCovers = playlist.songs
    ?.slice(0, 4)
    .map(song => song.album?.cover?.url)
    .filter(Boolean);

  return (
    <div className={styles.card} onClick={onClick}>
      <Box className={styles.coverWrapper}>
        <PlaylistCover
          coverUrl={playlist.cover?.url}
          albumCovers={albumCovers}
          isSmart={isSmart}
          size={160}
        />
        {isSmart && (
          <Badge className={styles.smartBadge} color="blue">
            <Iconify icon="carbon:rule" width={12} height={12} />
            Smart
          </Badge>
        )}
        {playlist.isCollaborative === "1" && (
          <Badge className={styles.collabBadge} color="gray">
            <Iconify icon="ph:users" width={12} height={12} />
          </Badge>
        )}
      </Box>

      <Text className={styles.title} size="2" weight="bold">
        {playlist.name}
      </Text>

      <Text className={styles.subtitle} size="1">
        {playlist.songsCount ?? 0} songs
        {playlist.isPublic === "1" && ' • Public'}
      </Text>
    </div>
  );
});

PlaylistCard.displayName = 'PlaylistCard';
