import { CoverGrid } from '@/app/modules/library-music/components/cover-grid';
import { PlaylistCard } from '@/app/modules/library-music-playlists/components/playlist-card/playlist-card';
import { Box, Flex, Skeleton } from '@radix-ui/themes';
import styles from './playlists.module.scss';
import { usePlaylistIndex } from '@/app/libs/api-client/gen/endpoints/playlist/playlist.ts';
import { Link} from 'react-router-dom';

export default function Playlists() {
  const {data, isLoading} = usePlaylistIndex({
    request: {
      params: {
        relations: 'songs,songs.album,cover',
      },
    },
  });

  return (
    <Flex justify="between" align="stretch" className={styles.playlistsLayout}>
      <Box m="3" className={styles.grid}>
        <CoverGrid>
          {isLoading && <PlaylistsSkeleton/>}
          {data?.data && (
            <>
              {data.data.map((playlist) => (
                <div className={styles.playlist} key={playlist.publicId}>
                  <Link to={playlist.publicId}><PlaylistCard playlist={playlist}/></Link>
                </div>
              ))}
            </>
          )}
        </CoverGrid>
      </Box>
    </Flex>
  );
}

function PlaylistsSkeleton() {
  const skeletons = [];

  for (let i = 0; i < 24; i++) {
    skeletons.push(<Skeleton key={i} height="220px" width="200px"/>);
  }

  return <>{skeletons}</>;
}
