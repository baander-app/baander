import { ScrollList, ScrollListItem } from '@/features/library-music/components/scroll-list';
import { Box, Flex } from '@mantine/core';
import {
  useAlbumServiceAlbumsIndex,
  useArtistServiceArtistsIndex,
  useGenreServiceGenresIndex,
} from '@/api-client/queries';
import { useEffect, useState } from 'react';
import { SongList } from '@/features/library-music/components/song-list/song-list.tsx';
import { useParams } from 'react-router-dom';
import styles from './songs.module.scss';
import { GenreResource } from '@/api-client/requests';

export default function Songs() {
  const { library } = useParams();
  if (!library) return <>Error no library</>;

  // @ts-ignore
  const { data: genreData, isFetching: isGenresFetching } = useGenreServiceGenresIndex<any>({ library });
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

  const { data: albumData } = useAlbumServiceAlbumsIndex({
    library,
    fields: 'title,slug',
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

  const { data: artistData } = useArtistServiceArtistsIndex({ library });
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
    <Box>
      <Flex justify="space-between" m="-12px">
        <ScrollList
          header="Genres"
          listItems={genres}
          totalCount={genres.length}
          style={{ height: 150, flexGrow: 1 }}
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

      <Box className={styles.songListContainer}>
        <SongList/>
      </Box>
    </Box>
  );

}
