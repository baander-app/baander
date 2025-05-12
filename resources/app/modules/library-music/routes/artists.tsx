import styles from './artists.module.scss';
import { CoverGrid } from '@/modules/library-music/components/cover-grid';
import { ArtistBigCircle } from '@/modules/library-music/components/artwork/artist-big-circle/artist-big-circle.tsx';
import { Container } from '@radix-ui/themes';
import { usePathParam } from '@/hooks/use-path-param.ts';
import { LibraryParams } from '@/modules/library-music/routes/_routes.tsx';
import { useArtistServiceGetApiLibrariesByLibraryArtists } from '@/api-client/queries';

export default function Artists() {
  const { library: libraryParam } = usePathParam<LibraryParams>();
  const {data: artistsData} = useArtistServiceGetApiLibrariesByLibraryArtists({
    library: libraryParam,
  })

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
