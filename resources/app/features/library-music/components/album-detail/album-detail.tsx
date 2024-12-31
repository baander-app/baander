import { Cover } from '@/features/library-music/components/artwork/cover';
import { useAlbumServiceAlbumsShow } from '@/api-client/queries';
import { SongResource } from '@/api-client/requests';
import { Table, Title, Text, Card, Group, Flex, Box, ScrollArea, Skeleton, BoxProps } from '@mantine/core';
import { AlertLoadingError } from '@/components/alerts/alert-loading-error.tsx';
import { useAppDispatch } from '@/store/hooks.ts';
import { setQueueAndSong } from '@/store/music/music-player-slice.ts';
import { TrackRow } from '@/components/music/track-row/track-row.tsx';

import styles from './album-detail.module.scss';
import { usePathParam } from '@/hooks/use-path-param.ts';
import { LibraryParams } from '@/features/library-music/routes/_routes.tsx';

interface AlbumDetailProps extends BoxProps {
  albumSlug: string;
}

export function AlbumDetail({ albumSlug, ...rest }: AlbumDetailProps) {
  const { library } = usePathParam<LibraryParams>();
  const { data, isFetching, isLoadingError, refetch } = useAlbumServiceAlbumsShow({
    album: albumSlug,
    library: library,
  });

  const genres = data?.genres?.map((genre) => genre.name).join(', ');

  return (
    <Box {...rest}>
      {isFetching && <AlbumDetailSkeleton/>}
      {isLoadingError && <AlertLoadingError retry={async () => {
        await refetch();
      }}/>}

      {data && (
        <Card>
          <Card.Section withBorder>
            <Flex>
              <Box p="sm">
                <Cover imgSrc={data?.coverUrl} size={180}/>
              </Box>

              <Box p="sm">
                <Title>{data?.title}</Title>
                {data?.albumArtist && (
                  <Text>{data.albumArtist.name}</Text>
                )}

                <Text>{genres} - {data?.year}</Text>
              </Box>
            </Flex>
          </Card.Section>

          <Group>
            {data?.songs && <AlbumSongs title={data.title} coverUrl={data.coverUrl} songs={data.songs}/>}
          </Group>
        </Card>
      )}
    </Box>
  );
}

interface AlbumSongProps {
  title: string;
  coverUrl?: string;
  songs: SongResource[];
}

function AlbumSongs({ songs }: AlbumSongProps) {
  const dispatch = useAppDispatch();

  const onSongClick = (song: SongResource, songs: SongResource[]) => {
    dispatch(setQueueAndSong({
      queue: songs,
      playPublicId: song.public_id,
    }));
  };

  const rows = songs.map((song) => (
    <TrackRow
      className={styles.trackRow}
      song={song}
      key={song.public_id}
      onClick={() => {
        console.log('row clicked')
        onSongClick(song, songs);
      }}
    />
  ));

  return (
    <>
      <ScrollArea h={600} w="100%">
        <Table highlightOnHover>
          <Table.Thead>
            <Table.Tr>
              <Table.Th>Track</Table.Th>
              <Table.Th>Title</Table.Th>
              <Table.Th>Duration</Table.Th>
            </Table.Tr>
          </Table.Thead>
          <Table.Tbody>{rows}</Table.Tbody>
        </Table>
      </ScrollArea>
    </>
  );
}


function AlbumDetailSkeleton() {
  return (
    <Card w={500}>
      <Card.Section>
        <Flex>
          <Box p="sm">
            <Skeleton height={180} width={180}/>
          </Box>

          <Box p="sm" w="100%">
            <Skeleton height={16} mt="sm"/>
            <Flex>
              <Skeleton height={8} mt="sm" width={50}/>
              <Skeleton height={8} mt="sm" ml="sm" width={50}/>
            </Flex>
          </Box>
        </Flex>
      </Card.Section>

      <Group>
        <Skeleton height={16}/>
        <Skeleton height={16}/>
        <Skeleton height={16}/>
        <Skeleton height={16}/>
        <Skeleton height={16}/>
        <Skeleton height={16}/>
        <Skeleton height={16}/>
      </Group>
    </Card>
  );
}