import { Text } from '@radix-ui/themes';

import styles from './artist-big-circle.module.scss';
import { ArtistResource } from '@/app/libs/api-client/gen/models';

interface ArtistBigCircleProps {
  artist: ArtistResource;
}

export function ArtistBigCircle({ artist }: ArtistBigCircleProps) {
  const hasPortrait = artist.portrait?.url;

  // Generate initials from artist name
  const getInitials = (name: string) => {
    const parts = name.trim().split(/\s+/);
    if (parts.length === 0) return '?';
    if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
  };

  const initials = getInitials(artist.name);

  return (
    <div className={styles.artistBigCircle}>
      <div className={styles.imageContainer}>
        {hasPortrait ? (
          <img
            src={artist.portrait!.url}
            alt={artist.name}
            className={styles.image}
          />
        ) : (
          <div className={styles.placeholder}>
            <Text size="5" weight="bold">{initials}</Text>
          </div>
        )}
      </div>
      <Text>{artist.name}</Text>
    </div>
  );
}
