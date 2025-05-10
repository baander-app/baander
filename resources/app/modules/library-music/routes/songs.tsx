import { ScrollList, ScrollListItem } from '@/modules/library-music/components/scroll-list';
import { Flex } from '@radix-ui/themes';
import {
  useAlbumServiceGetApiLibrariesByLibraryAlbums,
  useArtistServiceGetApiLibrariesByLibraryArtists,
  useGenreServiceGetApiGenres,
} from '@/api-client/queries';
import { useEffect, useState } from 'react';
import { SongList } from '@/modules/library-music/components/song-list/song-list.tsx';
import styles from './songs.module.scss';
import { GenreResource } from '@/api-client/requests';
import { usePathParam } from '@/hooks/use-path-param.ts';
import { LibraryParams } from '@/modules/library-music/routes/_routes.tsx';

export default function Songs() {
  const { library } = usePathParam<LibraryParams>();

  const { data: genreData } = useGenreServiceGetApiGenres({ librarySlug: library });
  const [genres, setGenres] = useState<ScrollListItem[]>([]);
  const [selectedGenres, setSelectedGenres] = useState<ScrollListItem | undefined>();

  useEffect(() => {
    if (genreData) {
      const data = genreData.data.map((x: GenreResource) => ({
        label: x.name,
        key: x.slug,
      }));

      setGenres(data);
    }
  }, [genreData]);

  const { data: albumData } = useAlbumServiceGetApiLibrariesByLibraryAlbums({
    library,
    genres: selectedGenres?.key ?? undefined,
  });
  const [albums, setAlbums] = useState<ScrollListItem[]>([]);
  useEffect(() => {
    if (albumData) {
      const items = albumData.data.map(x => ({
        label: x.title,
        key: x.slug,
      }));

      setAlbums(items);
    }
  }, [albumData]);

  const { data: artistData } = useArtistServiceGetApiLibrariesByLibraryArtists({ library });
  const [artists, setArtists] = useState<ScrollListItem[]>([]);

  useEffect(() => {
    if (artistData) {
      const items = artistData.data.map(x => ({
        label: x.name,
        key: x.slug,
      }));
      setArtists(items);
    }
  }, [artistData]);

  return (
    <>
      <Flex style={{ width: '100vw' }}>
        <ScrollList
          header="Genres"
          listItems={genres}
          totalCount={genres.length}
          style={{ height: 150 }}
          onItemPress={item => setSelectedGenres(item)}
        />

        <ScrollList
          header="Artists"
          listItems={artists}
          totalCount={artists.length}
          style={{ height: 150 }}
        />

        <ScrollList
          header="Albums"
          listItems={albums}
          totalCount={albums.length}
          style={{ height: 150 }}
        />
      </Flex>

      <SongList/>
    </>
  );

}
