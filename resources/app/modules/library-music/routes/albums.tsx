import { useState } from 'react';

import styles from './albums.module.scss';
import { AlbumDetail } from '@/modules/library-music/components/album-detail/album-detail.tsx';
import { CoverGrid } from '@/modules/library-music/components/cover-grid';
import { Album } from '@/modules/library-music/components/album';
import { useAlbumServiceGetApiLibrariesByLibraryAlbums } from '@/api-client/queries';
import { Box, ContextMenu, Flex, Skeleton } from '@radix-ui/themes';
import { usePathParam } from '@/hooks/use-path-param.ts';
import { LibraryParams } from '@/modules/library-music/routes/_routes.tsx';
import { AlbumResource } from '@/api-client/requests';
import * as motion from "framer-motion/client"


function AlbumContextMenu({ album }: { album: AlbumResource }) {
  return (
    <ContextMenu.Content>
      <ContextMenu.Item>Play</ContextMenu.Item>
      <ContextMenu.Item>Edit</ContextMenu.Item>
      <ContextMenu.Separator />
      <ContextMenu.Item color="red">Delete</ContextMenu.Item>
    </ContextMenu.Content>
  )
}

export default function Albums() {
  const { library: libraryParam } = usePathParam<LibraryParams>();
  const [showAlbumDetail, setShowAlbumDetail] = useState<string | null>(null);
  const { data, isLoading } = useAlbumServiceGetApiLibrariesByLibraryAlbums({ library: libraryParam, relations: 'artists,cover' });

  return (
    <Flex justify="between" align="stretch" className={styles.albumsLayout}>
      <Box m="3" className={styles.grid}>
        <CoverGrid>
          {isLoading && <AlbumsSkeleton/>}
          {data?.data && (
            <>
              {data.data.map((album) => (
                <div className={styles.album} key={album.slug}>
                  <ContextMenu.Root>
                    <ContextMenu.Trigger>
                      <Album
                        title={album.title}
                        primaryArtist={album?.artists?.map(x => x.name).join(',') ?? 'Unknown'}
                        imgSrc={album?.cover?.url ?? undefined}
                        onClick={() => setShowAlbumDetail(album.slug)}
                      />
                    </ContextMenu.Trigger>

                    <AlbumContextMenu album={album} />
                  </ContextMenu.Root>
                </div>
              ))}
            </>
          )}
        </CoverGrid>
      </Box>

      <Box display="block" minHeight="300px" minWidth="300px" className={styles.sidebar} mt="2" mr="2">
        {showAlbumDetail && (
          <motion.div
            initial={{ opacity: 0, scale: 0.5 }}
            animate={{ opacity: 1, scale: 1 }}
            transition={{
              duration: 0.3,
              ease: [0, 0.71, 0.2, 1.01],
            }}
          >
            <AlbumDetail albumSlug={showAlbumDetail}/>
          </motion.div>
        )}
      </Box>
    </Flex>
  );
}

function AlbumsSkeleton() {
  const generateItems = 24;

  const skeletons = [];

  for (let i = 0; i < generateItems; i++) {
    skeletons.push(<Skeleton key={i} height="220px" width="200px"/>);
  }

  return (
    <>
      {skeletons}
    </>
  );
}