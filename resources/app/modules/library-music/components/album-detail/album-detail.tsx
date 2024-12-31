import { Cover } from '@/modules/library-music/components/artwork/cover';
import { useAlbumServiceAlbumsShow } from '@/api-client/queries';
import { SongResource } from '@/api-client/requests';
import { Table, Text, Card, Group, Flex, Box, ScrollArea, Skeleton, BoxProps } from '@mantine/core';
import { AlertLoadingError } from '@/ui/alerts/alert-loading-error.tsx';
import { useAppDispatch } from '@/store/hooks.ts';
import { setQueueAndSong } from '@/store/music/music-player-slice.ts';
import { TrackRow } from '@/ui/music/track-row/track-row.tsx';

import styles from './album-detail.module.scss';
import { usePathParam } from '@/hooks/use-path-param.ts';
import { LibraryParams } from '@/modules/library-music/routes/_routes.tsx';
import { generateBlurhashBackgroundImage } from '@/libs/blurhash/generate-bg-image.ts';

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
  const blurhash = data?.cover && generateBlurhashBackgroundImage(data.cover.blurhash, 128, 128);

  return (
    <Box {...rest}>
      {isFetching && <AlbumDetailSkeleton/>}
      {isLoadingError && <AlertLoadingError retry={async () => {
        await refetch();
      }}/>}

      {data && (
        <Card className={styles.card}>
          {blurhash && (
            <div
              className={styles.image}
              style={{
                backgroundImage: blurhash.backgroundUrl,
              }}
            />
          )}

          <div className={styles.content}>
            <div>
              <Flex align="stretch">
                <Box p="sm">
                  <Cover imgSrc={data?.cover?.url} size={180}/>
                </Box>

                <Flex p="sm" align="start" direction="column" justify="center">
                  <Text size="lg" fw="600">{data?.title}</Text>
                  {data?.artists && (
                    <Text >{data.artists.map(x => x.name).join(', ')}</Text>
                  )}

                  <Text>{genres} - {data?.year}</Text>
                </Flex>

                <Box></Box>
              </Flex>

              <Group>
                {data?.songs && <AlbumSongs title={data.title} coverUrl={data.cover?.url} songs={data.songs}/>}
              </Group>
            </div>
          </div>
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
        console.log('row clicked');
        onSongClick(song, songs);
      }}
    />
  ));

  return (
    <>
      <ScrollArea mih="inherit" w="100%">
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