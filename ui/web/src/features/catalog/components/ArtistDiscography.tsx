import styled from 'styled-components'
import { useMemo } from 'react';
import { AlbumGridCard } from './AlbumGridCard';
import { Skeleton } from '@/shared/components/ui/skeleton';
import { textRole } from '@/shared/theme';
import type { AlbumSummary } from '../types';

const Grid = styled.div`
  display: grid;
  gap: 1rem;
`;

const NoAlbumsText = styled.p`
  ${textRole('body')}
  color: var(--color-muted-foreground);
`;

interface ArtistDiscographyProps {
  albums: AlbumSummary[] | undefined;
  isLoading: boolean;
}

export function ArtistDiscography({albums, isLoading}: ArtistDiscographyProps) {

  const albumCards = useMemo(() => {
    if (!albums || albums.length === 0) return null;

    return albums.map((album) => {

      return (
        <AlbumGridCard
          key={album.publicId}
          publicId={album.publicId}
          title={album.title}
          artistNames={album.artists.map((a) => a.name).filter(Boolean)}
          imageUrl={album.coverImage?.url}
          blurhash={album.coverImage?.blurhash}
          language={album.language}
        />
      );
    });
  }, [albums]);

  if (isLoading) {
    return (
      <Grid style={{gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))'}}>
        {Array.from({length: 6}).map((_, i) => (
          <div key={i}>
            <Skeleton style={{aspectRatio: '1', borderRadius: 'var(--radius-md)'}}/>
            <Skeleton style={{marginTop: '0.5rem', height: '1rem', width: '75%', borderRadius: 'var(--radius-sm)'}}/>
            <Skeleton style={{marginTop: '0.25rem', height: '0.75rem', width: '50%', borderRadius: 'var(--radius-sm)'}}/>
          </div>
        ))}
      </Grid>
    );
  }

  if (!albums || albums.length === 0) {
    return <NoAlbumsText>No albums yet</NoAlbumsText>;
  }

  return (
    <Grid style={{gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))'}}>
      {albumCards}
    </Grid>
  );
}
