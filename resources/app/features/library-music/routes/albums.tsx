import { useState } from 'react';

import styles from './albums.module.scss';
import { AlbumDetail } from '@/features/library-music/components/album-detail/album-detail.tsx';
import { CoverGrid } from '@/features/library-music/components/cover-grid';
import { Album } from '@/features/library-music/components/album';
import { useAlbumServiceAlbumsIndex } from '@/api-client/queries';
import { Box, Flex, Skeleton } from '@mantine/core';

export default function Albums() {
  const [showAlbumDetail, setShowAlbumDetail] = useState<string | null>(null);
  const {data, isLoading} = useAlbumServiceAlbumsIndex({library: 'music', relations: 'cover'});

  return (
    <Flex justify="space-between">
      <Box p="6px" className={styles.grid}>
        <CoverGrid>
          {isLoading && <AlbumsSkeleton/>}
          {data?.data && (
            <>
              {data.data.map((album) => (
                <div className={styles.album} key={album.slug}>
                  <Album
                    title={album.title}
                    primaryArtist={album.albumArtist?.name}
                    imgSrc={album.coverUrl ?? undefined}
                    onClick={() => setShowAlbumDetail(album.slug)}

                  />
                </div>
              ))}
            </>
          )}
        </CoverGrid>
      </Box>

      {showAlbumDetail && (
        <Box maw="40%">
          <AlbumDetail albumSlug={showAlbumDetail}/>
        </Box>
      )}
    </Flex>
  );
}

function AlbumsSkeleton() {
  const generateItems = 24;

  const skeletons = [];

  for (let i = 0; i < generateItems; i++) {
    skeletons.push(<Skeleton key={i} height={220} width={200}/>);
  }

  return (
    <>
      {skeletons}
    </>
  );
}