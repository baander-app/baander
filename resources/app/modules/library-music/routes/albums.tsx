import { useState } from 'react';
import styles from './albums.module.scss';
import { AlbumDetail } from '@/modules/library-music/components/album-detail/album-detail.tsx';
import { CoverGrid } from '@/modules/library-music/components/cover-grid';
import { Album } from '@/modules/library-music/components/album';
import { Box, ContextMenu, Dialog, Flex, Skeleton } from '@radix-ui/themes';
import { usePathParam } from '@/hooks/use-path-param.ts';
import { LibraryParams } from '@/modules/library-music/routes/_routes.tsx';
import { motion } from 'motion/react';
import { useDisclosure } from '@/hooks/use-disclosure.ts';
import { AlbumEditor } from '@/modules/library-music/components/album-editor/album-editor.tsx';
import { AlbumResource } from '@/libs/api-client/gen/models';
import { useAlbumsIndex } from '@/libs/api-client/gen/endpoints/album/album.ts';

function AlbumContextMenu({ album }: { album: AlbumResource }) {
  const [showEditor, editorHandlers] = useDisclosure(false);

  return (
    <>
      <ContextMenu.Content>
        <ContextMenu.Item>Play</ContextMenu.Item>
        <ContextMenu.Item onClick={() => editorHandlers.toggle()}>Edit</ContextMenu.Item>
        <ContextMenu.Separator/>
        <ContextMenu.Item color="red">Delete</ContextMenu.Item>
      </ContextMenu.Content>


      <Dialog.Root open={showEditor} onOpenChange={editorHandlers.toggle}>
        <Dialog.Content style={{
          backgroundColor: 'var(--color-background)',
          border: '1px solid var(--gray-6)',
          borderRadius: '8px',
          boxShadow: '0 4px 12px rgba(0, 0, 0, 0.15)',
          padding: '60px',
          maxWidth: '650px',
          width: '100%',
        }}>
          <Dialog.Title>Edit Album</Dialog.Title>
          <Dialog.Description>
            Make changes to the album information.
          </Dialog.Description>

          <AlbumEditor album={album} onSubmit={() => {
            editorHandlers.close();
          }} librarySlug="muzak"/>
        </Dialog.Content>
      </Dialog.Root>

    </>
  );
}

export default function Albums() {
  const { library: libraryParam } = usePathParam<LibraryParams>();
  const [showAlbumDetail, setShowAlbumDetail] = useState<string | null>(null);
  const { data, isLoading } = useAlbumsIndex(libraryParam, {
    relations: 'artists,cover',
  });

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

                    <AlbumContextMenu album={album}/>
                  </ContextMenu.Root>
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
        style={{ alignSelf: 'flex-start' }} // Add this for flex containers
      >
        {showAlbumDetail && (
          <motion.div
            key={showAlbumDetail}
            layout
            initial={{ opacity: 0, scale: 0.3 }}
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
