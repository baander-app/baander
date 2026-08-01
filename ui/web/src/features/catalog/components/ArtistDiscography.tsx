import styled from 'styled-components'
import { useMemo } from 'react';
import { AlbumGridCard } from './AlbumGridCard';
import { Skeleton } from '@/shared/components/ui/skeleton';
import type { AlbumSummary } from '../types';

const Grid = styled.div`
  display: grid;
  gap: 1rem;
`;

const NoAlbumsText = styled.p`
  font-size: 0.875rem;
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
      const artistName = album.artists.map((a) => a.name).join(', ') || undefined;

      return (
        <AlbumGridCard
          key={album.publicId}
          publicId={album.publicId}
          title={album.title}
          artistName={artistName}
          imageUrl={album.coverImage?.url}
          blurhash={album.coverImage?.blurhash}
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
