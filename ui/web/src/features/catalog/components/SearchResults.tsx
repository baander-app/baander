import styled from 'styled-components'
import { Link } from 'react-router-dom';
import { ImageOff } from 'lucide-react';
import { useGetAlbumIndex, useGetArtistIndex, useGetSongIndex } from '@/shared/api-client/gen/endpoints';
import { asAlbums, asArtists, asSongs } from '../utils/api-adapters';
import type { SongSummary } from '../types';
import { type Track, usePlayerStore } from '@/features/player/stores/player-store';
import { formatDuration } from '@/shared/utils/format-duration';
import { AlbumContextMenu } from './menus/AlbumContextMenu';
import { ArtistContextMenu } from './menus/ArtistContextMenu';
import { SongContextMenu } from './menus/SongContextMenu';
import { interactiveTransition } from '@/shared/theme';

const Container = styled.div`
  & > * + * {
    margin-top: 2rem;
  }
`

const SectionTitle = styled.h2`
  margin-bottom: 0.75rem;
  font-size: 0.875rem;
  font-weight: 600;
  letter-spacing: -0.025em;
`

const Grid = styled.div`
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1rem;

  @media (min-width: 640px) {
    grid-template-columns: repeat(3, 1fr);
  }
  @media (min-width: 768px) {
    grid-template-columns: repeat(4, 1fr);
  }
  @media (min-width: 1024px) {
    grid-template-columns: repeat(5, 1fr);
  }
`

const ArtistLink = styled(Link)`
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  text-align: center;
`

const ArtistAvatar = styled.div`
  margin: 0 auto;
  display: flex;
  height: 6rem;
  width: 6rem;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background-color: var(--color-secondary);
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--color-muted-foreground);
`

const ArtistName = styled.div`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.75rem;
  font-weight: 500;
`

const AlbumLink = styled(Link)`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const AlbumCover = styled.div`
  aspect-ratio: 1;
  overflow: hidden;
  border-radius: var(--radius-lg);
  background-color: var(--color-secondary);
`

const AlbumCoverImage = styled.img`
  height: 100%;
  width: 100%;
  object-fit: cover;
`

const AlbumPlaceholder = styled.div`
  display: flex;
  height: 100%;
  width: 100%;
  align-items: center;
  justify-content: center;
  color: color-mix(in srgb, var(--color-muted-foreground) 20%, transparent);
`

const AlbumTitle = styled.div`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.75rem;
  font-weight: 500;
`

const AlbumArtist = styled.div`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 11px;
  color: var(--color-muted-foreground);
`

const SongButton = styled.button`
  display: flex;
  width: 100%;
  align-items: center;
  gap: 1rem;
  border-radius: var(--radius-md);
  padding: 0.375rem 0.75rem;
  text-align: left;
  ${interactiveTransition(['color', 'background-color'])}
  background: none;
  border: none;
  cursor: pointer;

  &:hover {
    background-color: color-mix(in srgb, var(--color-accent) 50%, transparent);
  }
`

const SongInfo = styled.div`
  min-width: 0;
  flex: 1;
`

const SongTitle = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.875rem;
  font-weight: 500;
  color: color-mix(in srgb, var(--color-foreground) 80%, transparent);
`

const SongArtist = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 11px;
  color: var(--color-muted-foreground);
`

const SongDuration = styled.span`
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const SongList = styled.div`
  & > * + * {
    margin-top: 0.125rem;
  }
`

const EmptyContainer = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  padding: 4rem 0;
`

const EmptyText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function asString(val: any): string {
  return val ?? '';
}

export function SearchResults({query}: { query: string }) {
  const {data: albumsData} = useGetAlbumIndex({q: query, limit: 20});
  const {data: artistsData} = useGetArtistIndex({q: query, limit: 20});
  const {data: songsData} = useGetSongIndex({q: query, limit: 20});
  const playTrack = usePlayerStore((s) => s.playTrack);

  const albums = asAlbums(albumsData);
  const artists = asArtists(artistsData);
  const songs = asSongs(songsData);

  const hasResults = albums.length > 0 || artists.length > 0 || songs.length > 0;

  const handlePlaySong = (_song: SongSummary, songList: SongSummary[], index: number) => {
    const tracks: Track[] = songList.map((s) => ({
      publicId: s.publicId,
      title: s.title,
      artistName: s.artistName ?? undefined,
      albumName: s.albumName ?? undefined,
      albumPublicId: s.albumId,
      duration: s.length ?? undefined,
    }));
    playTrack(tracks[index], tracks);
  };

  if (!query) {
    return null;
  }

  if (!hasResults) {
    return (
      <EmptyContainer>
        <EmptyText>No results for &ldquo;{query}&rdquo;</EmptyText>
      </EmptyContainer>
    );
  }

  return (
    <Container>
      {/* Artists */}
      {artists.length > 0 && (
        <section>
          <SectionTitle>Artists</SectionTitle>
          <Grid>
            {artists.map((artist, i) => (
              <ArtistContextMenu key={asString(artist?.publicId) ?? i} artist={{ publicId: asString(artist?.publicId), name: asString(artist?.name) }}>
                <ArtistLink to={`/artists/${asString(artist?.publicId)}`}>
                  <ArtistAvatar>
                    {asString(artist?.name).charAt(0).toUpperCase()}
                  </ArtistAvatar>
                  <ArtistName>{asString(artist?.name)}</ArtistName>
                </ArtistLink>
              </ArtistContextMenu>
            ))}
          </Grid>
        </section>
      )}

      {/* Albums */}
      {albums.length > 0 && (
        <section>
          <SectionTitle>Albums</SectionTitle>
          <Grid>
            {albums.map((album, i) => (
              <AlbumContextMenu key={asString(album?.publicId) ?? i} album={{ publicId: asString(album?.publicId), title: asString(album?.title), artistName: album?.artists?.[0]?.name }}>
                <AlbumLink to={`/albums/${asString(album?.publicId)}`}>
                  <AlbumCover>
                    {album?.coverImage?.url ? (
                      <AlbumCoverImage src={album.coverImage.url} alt={album.title} />
                    ) : (
                      <AlbumPlaceholder>
                        <ImageOff size={24} strokeWidth={1.5} />
                      </AlbumPlaceholder>
                    )}
                  </AlbumCover>
                  <AlbumTitle>{album?.title}</AlbumTitle>
                  <AlbumArtist>{album?.artists?.[0]?.name}</AlbumArtist>
                </AlbumLink>
              </AlbumContextMenu>
            ))}
          </Grid>
        </section>
      )}

      {/* Songs */}
      {songs.length > 0 && (
        <section>
          <SectionTitle>Songs</SectionTitle>
          <SongList>
            {songs.map((song, index) => (
              <SongContextMenu
                key={asString(song?.publicId) ?? index}
                song={{
                  publicId: asString(song?.publicId),
                  title: song.title,
                  artistName: song.artistName ?? undefined,
                  albumName: song.albumName ?? undefined,
                  duration: song.length ?? undefined,
                }}
              >
                <SongButton
                  type="button"
                  onClick={() => handlePlaySong(song, songs, index)}
                >
                  <SongInfo>
                    <SongTitle>{song.title}</SongTitle>
                    <SongArtist>{song.artistName}</SongArtist>
                  </SongInfo>
                  <SongDuration>
                    {song.length != null ? formatDuration(song.length) : ''}
                  </SongDuration>
                </SongButton>
              </SongContextMenu>
            ))}
          </SongList>
        </section>
      )}
    </Container>
  );
}
