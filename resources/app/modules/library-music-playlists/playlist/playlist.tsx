import { SongTable } from '@/components/song-table/song-table';
import { usePathParam } from '@/hooks/use-path-param';
import { usePlaylistServiceGetApiPlaylistsByPlaylist } from '@/api-client/queries';
import { MusicPlaylistParams } from '@/modules/library-music-playlists/_routes.tsx';
import styles from './playlist.module.scss';
import { Box, Flex, Heading, Text } from '@radix-ui/themes';

export function PlayList() {
  const { playlistId } = usePathParam<MusicPlaylistParams>();
  const {
    data,
  } = usePlaylistServiceGetApiPlaylistsByPlaylist({
    playlist: playlistId,
  });

  return (
    <Flex direction="column" >
      <Box>
        <Heading size="8">{data?.name}</Heading>

        {data?.description && (
          <Text>{data.description}</Text>
        )}
      </Box>

      <SongTable
        songs={data?.songs || []}
        description={data?.description}
        className={styles.playlistTable}
      />
    </Flex>




  );
}
