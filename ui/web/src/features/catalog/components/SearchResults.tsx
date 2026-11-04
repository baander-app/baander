import { useContextPanelStore } from '@/features/layout/stores/context-panel-store'
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
import { interactiveTransition, textRole } from '@/shared/theme';
import { ContentText } from '@/shared/typography';

const Container = styled.div`
  & > * + * {
    margin-top: 2rem;
  }
`

const SectionTitle = styled.h2`
  margin-bottom: 0.75rem;
  ${textRole('title')}
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
  color: var(--color-muted-foreground);
`

const ArtistName = styled(ContentText)`
  display: block;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
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

const AlbumTitle = styled(ContentText)`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 500;
`

const AlbumArtist = styled(ContentText)`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
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
    background-color: color-mix(in srgb, var(--color-highlight) 50%, transparent);
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
  ${textRole('body-sm')}
  color: color-mix(in srgb, var(--color-foreground) 80%, transparent);
`

const SongTitleText = styled(ContentText)`
  font-weight: 500;
`

const SongArtist = styled(ContentText)`
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--color-muted-foreground);
`

const SongDuration = styled.span`
  ${textRole('mono-sm')}
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
  ${textRole('body')}
  color: var(--color-muted-foreground);
`

export function SearchResults({query}: { query: string }) {
  const {data: albumsData} = useGetAlbumIndex({q: query, limit: 20});
  const {data: artistsData} = useGetArtistIndex({q: query, limit: 20});
  const {data: songsData} = useGetSongIndex({q: query, limit: 20});
  const playTrack = usePlayerStore((s) => s.playTrack);
  const setSelectedItem = useContextPanelStore((s) => s.setSelectedItem);

  const albums = asAlbums(albumsData);
  const artists = asArtists(artistsData);
  const songs = asSongs(songsData);

  const hasResults = albums.length > 0 || artists.length > 0 || songs.length > 0;

  const handlePlaySong = (_song: SongSummary, songList: SongSummary[], index: number) => {
    if (_song.available !== true) {
      setSelectedItem({ type: 'song', publicId: _song.publicId });
      return;
    }
    const tracks: Track[] = songList.map((s) => ({
      publicId: s.publicId,
      available: s.available === true,
      title: s.title,
      artistName: s.artistName ?? undefined,
      albumName: s.albumName ?? undefined,
      albumLanguage: s.albumLanguage ?? undefined,
      albumPublicId: s.albumId,
      duration: s.length ?? undefined,
    }));
    playTrack(tracks[index], tracks.filter((track) => track.available === true));
  };

  if (!query) {
    return null;
  }

  if (!hasResults) {
    return (
      <EmptyContainer>
        <EmptyText>No results for &ldquo;<ContentText textRole="body" text={query} />&rdquo;</EmptyText>
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
            {artists.map((artist) => (
              <ArtistContextMenu key={artist.publicId} artist={{ publicId: artist.publicId, name: artist.name }}>
                <ArtistLink to={`/artists/${artist.publicId}`}>
                  <ArtistAvatar>
                    <ContentText textRole="title" text={Array.from(artist.name)[0]?.toUpperCase() ?? ''} aria-hidden="true" />
                  </ArtistAvatar>
                  <ArtistName textRole="caption" text={artist.name} />
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
            {albums.map((album) => (
              <AlbumContextMenu key={album.publicId} album={{ publicId: album.publicId, title: album.title, artistName: album.artists[0]?.name }}>
                <AlbumLink to={`/albums/${album.publicId}`}>
                  <AlbumCover>
                    {album.coverImage?.url ? (
                      <AlbumCoverImage src={album.coverImage.url} alt={album.title} />
                    ) : (
                      <AlbumPlaceholder>
                        <ImageOff size={24} strokeWidth={1.5} />
                      </AlbumPlaceholder>
                    )}
                  </AlbumCover>
                  <AlbumTitle textRole="caption" text={album.title} language={album.language} forwardedAs="div" />
                  <AlbumArtist textRole="caption" text={album.artists[0]?.name ?? ''} forwardedAs="div" />
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
                key={song.publicId}
                song={{
                  publicId: song.publicId,
                  available: song.available,
                  title: song.title,
                  artistName: song.artistName ?? undefined,
                  albumName: song.albumName ?? undefined,
                  albumLanguage: song.albumLanguage ?? undefined,
                  duration: song.length ?? undefined,
                }}
              >
                <SongButton
                  type="button"
                  onClick={() => handlePlaySong(song, songs, index)}
                >
                  <SongInfo>
                    <SongTitle>
                      <SongTitleText textRole="body-sm" text={song.title} language={song.albumLanguage} />
                      {song.available === false && <span> · Unavailable</span>}
                    </SongTitle>
                    <SongArtist textRole="caption" text={song.artistName ?? ''} forwardedAs="p" />
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
