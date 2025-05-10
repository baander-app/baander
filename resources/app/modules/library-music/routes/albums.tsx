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
  const { data, isLoading } = useAlbumServiceGetApiLibrariesByLibraryAlbums({ library: libraryParam, relations: 'cover' });

  return (
    <Flex justify="between" align="stretch">
      <Box p="6px" className={styles.grid}>
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
                        primaryArtist={album?.artists?.map(x => x.name).join(',')}
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

      {showAlbumDetail && (
        <AlbumDetail albumSlug={showAlbumDetail}/>
      )}
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