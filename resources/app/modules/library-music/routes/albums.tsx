import { useState } from 'react';

import styles from './albums.module.scss';
import { AlbumDetail } from '@/modules/library-music/components/album-detail/album-detail.tsx';
import { CoverGrid } from '@/modules/library-music/components/cover-grid';
import { Album } from '@/modules/library-music/components/album';
import { useAlbumServiceAlbumsIndex } from '@/api-client/queries';
import { Box, Flex, Skeleton, useMantineTheme } from '@mantine/core';
import { usePathParam } from '@/hooks/use-path-param.ts';
import { LibraryParams } from '@/modules/library-music/routes/_routes.tsx';
import { AlbumResource } from '@/api-client/requests';
import { ContextMenuContent, useContextMenu } from 'mantine-contextmenu';

export default function Albums() {
  const { library: libraryParam } = usePathParam<LibraryParams>();
  const [showAlbumDetail, setShowAlbumDetail] = useState<string | null>(null);
  const { data, isLoading } = useAlbumServiceAlbumsIndex({ library: libraryParam, relations: 'cover' });
  const { showContextMenu } = useContextMenu();
  const theme = useMantineTheme();

  const getContextMenuTemplate = (album: AlbumResource): ContextMenuContent => ([
    {
      title: `Play ${album.title}`,
      key: 'play',
      onClick: () => {

      },
    },
    {
      title: 'Edit',
      key: 'edit',
      onClick: () => {

      },
    },
    { key: 'divider' },
    {
      title: 'Delete',
      key: 'delete',
      color: theme.colors.red[5],
      onClick: () => {

      },
    },
  ]);

  return (
    <Flex justify="space-between" align="stretch">
      <Box p="6px" className={styles.grid}>
        <CoverGrid>
          {isLoading && <AlbumsSkeleton/>}
          {data?.data && (
            <>
              {data.data.map((album) => (
                <div className={styles.album} key={album.slug}>
                  <Album
                    title={album.title}
                    primaryArtist={album?.artists?.map(x => x.name).join(',')}
                    imgSrc={album?.cover?.url ?? undefined}
                    onClick={() => setShowAlbumDetail(album.slug)}
                    onContextMenu={showContextMenu(getContextMenuTemplate(album))}
                  />
                </div>
              ))}
            </>
          )}
        </CoverGrid>
      </Box>

      {showAlbumDetail && (
        <AlbumDetail miw="35%" albumSlug={showAlbumDetail}/>
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