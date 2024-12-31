import styles from './album-detail.module.scss';
import { Cover } from '@/features/library-music/components/artwork/cover';
import { useAlbumServiceAlbumsShow } from '@/api-client/queries';
import { SongResource } from '@/api-client/requests';
import { useMusicSource } from '@/providers';
import { Table, Title, Text, Card, Group, Flex, Box, ScrollArea, Skeleton } from '@mantine/core';
import { AlertLoadingError } from '@/components/alerts/alert-loading-error.tsx';
import { formatDuration } from '@/support/time';

interface AlbumDetailProps {
  albumSlug: string;
}

export function AlbumDetail({albumSlug}: AlbumDetailProps) {
  const {data, isFetching, isLoadingError, refetch} = useAlbumServiceAlbumsShow({album: albumSlug, library: 'music'});

  return (
    <>
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

                <Text>album.genre - {data?.year}</Text>
              </Box>
            </Flex>
          </Card.Section>

          <Group>
            {data?.songs && <AlbumSongs title={data.title} coverUrl={data.coverUrl} songs={data.songs}/>}
          </Group>
        </Card>
      )}
    </>
  );
}

interface AlbumSongProps {
  title: string;
  coverUrl?: string;
  songs: SongResource[];
}

function AlbumSongs({title, coverUrl, songs}: AlbumSongProps) {
  const musicSource = useMusicSource();

  const playSong = (streamUrl: string) => {
    musicSource.setSource(streamUrl);
    musicSource.setDetails({
      title,
      coverUrl,
    });
  };

  const rows = songs.map(song => (
    <Table.Tr
      key={song.public_id}
      className={styles.trackRow}
      onClick={() => {
        if (song.stream) {
          playSong(song.stream)
        }
      }}
    >
      <Table.Td>{song.track}</Table.Td>
      <Table.Td>{song.title}</Table.Td>
      <Table.Td>{formatDuration(song.length!)}</Table.Td>
    </Table.Tr>
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