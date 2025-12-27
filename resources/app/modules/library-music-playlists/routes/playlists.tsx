import { CoverGrid } from '@/app/modules/library-music/components/cover-grid';
import { PlaylistCard } from '@/app/modules/library-music-playlists/components/playlist-card/playlist-card';
import { PlaylistDetail } from '@/app/modules/library-music-playlists/components/playlist-detail/playlist-detail';
import { Box, Flex, Skeleton } from '@radix-ui/themes';
import { useState } from 'react';
import styles from './playlists.module.scss';
import { usePlaylistIndex } from '@/app/libs/api-client/gen/endpoints/playlist/playlist.ts';

export default function Playlists() {
  const [selectedPlaylistId, setSelectedPlaylistId] = useState<string | null>(null);
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
                  <PlaylistCard
                    playlist={playlist}
                    onClick={() => setSelectedPlaylistId(playlist.publicId)}
                  />
                </div>
              ))}
            </>
          )}
        </CoverGrid>
      </Box>

      <Box
        display="block"
        position="sticky"
        right="8px"
        top="8px"
        minHeight="300px"
        minWidth="300px"
        className={styles.sidebar}
        style={{alignSelf: 'flex-start'}}
      >
        {selectedPlaylistId && (
          <PlaylistDetail
            playlistId={selectedPlaylistId}
            librarySlug="music"
          />
        )}
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
