import { Text } from '@radix-ui/themes';

import styles from './artist-big-circle.module.scss';
import { ArtistResource } from '@/api-client/requests';

interface ArtistBigCircleProps {
  artist: ArtistResource;
}
export function ArtistBigCircle({ artist }: ArtistBigCircleProps) {
  return (
    <div className={styles.artistBigCircle}>
      <div className={styles.imageContainer}>
        <img
          src={'https://place-hold.it/300'}
          alt={artist.name}
          className={styles.image}
        />
      </div>
      <Text>{artist.name}</Text>
    </div>
  )
}
