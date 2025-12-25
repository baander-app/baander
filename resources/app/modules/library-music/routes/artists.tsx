import styles from './artists.module.scss';
import { CoverGrid } from '@/app/modules/library-music/components/cover-grid';
import { ArtistBigCircle } from '@/app/modules/library-music/components/artwork/artist-big-circle/artist-big-circle.tsx';
import { Container } from '@radix-ui/themes';
import { usePathParam } from '@/app/hooks/use-path-param.ts';
import { LibraryParams } from '@/app/modules/library-music/routes/_routes.tsx';
import { useArtistsIndex } from '@/app/libs/api-client/gen/endpoints/artist/artist.ts';

export default function Artists() {
  const { library: libraryParam } = usePathParam<LibraryParams>();
  const {data: artistsData} = useArtistsIndex(libraryParam)

  return (
    <Container className={styles.artistsLayout}>
      <CoverGrid style={{ gap: '32px' }}>
        {artistsData?.data.map((artist, index) => (
          <div className={styles.artist} key={index}>
            <ArtistBigCircle artist={artist} />
          </div>
        ))}
      </CoverGrid>
    </Container>
  );
}
